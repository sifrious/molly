<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Sifrious\Molly\Actions\ApproveTask;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Actions\RecordPullRequestOpened;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Contracts\DisplayStatus;
use Sifrious\Molly\Mcp\MollyServer;
use Sifrious\Molly\Mcp\MollyTask;
use Sifrious\Molly\Models\Task;

/*
 * MCP callers are agents. Every molly_task operation that records a human decision must
 * refuse, change nothing, and name the Artisan command a person runs instead. On the
 * command line, each of those commands refuses without --approve and changes nothing.
 */

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-mcp-human-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($this->workspace);
    commitGitWorkspace($this->workspace);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

/** A task whose required checks passed, so Molly waits for a person to approve it. */
function taskAwaitingHumanDecision(string $workspace, array $source = []): Task
{
    $task = app(CreateTask::class)->handle('Return Hello.', $workspace, ['app/Greeting.php'], 'tests/GreetingTest.php', $source);
    test()->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn(['status' => 'ok', 'probes' => []]);
    test()->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true]);
    test()->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle')->once()->andReturn([
        'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']),
        'findings' => [],
    ]);
    ChangeWriter::fake([['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]]])->preventStrayPrompts();
    config(['molly.parallel_checks' => false]);
    app(StartTask::class)->handle($task->id);

    return $task->fresh();
}

/** Everything a human decision could change: the task row, its runs, the test file, and every file under .molly. */
function humanDecisionState(Task $task): array
{
    $files = [];
    if (is_dir($task->workspace.'/.molly')) {
        foreach (File::allFiles($task->workspace.'/.molly', true) as $file) {
            $files[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($files);

    return [
        'task' => Task::findOrFail($task->id)->getAttributes(),
        'runs' => $task->runs()->count(),
        'test' => hash_file('sha256', $task->workspace.'/'.$task->test_path),
        'molly' => $files,
    ];
}

it('refuses a human decision over MCP, changes nothing, and names the command a person runs', function (string $operation, Closure $prepare, array $arguments, string $command) {
    $task = $prepare($this->workspace);
    $status = app(RecordLifecycleEvent::class)->load($this->workspace)->displayStatus($task->id);
    Process::fake();
    $before = humanDecisionState($task);

    MollyServer::tool(MollyTask::class, ['operation' => $operation, 'id' => $task->id, 'approve' => true, ...$arguments])
        ->assertHasErrors([
            'HUMAN_APPROVAL_REQUIRED: Only a person can',
            'molly_task changed nothing.',
            'Ask a person to run: php artisan '.str_replace('TASK', $task->id, $command),
        ]);

    expect(humanDecisionState($task))->toBe($before)
        ->and(app(RecordLifecycleEvent::class)->load($this->workspace)->displayStatus($task->id))->toBe($status);
    Process::assertNothingRan();
})->with([
    'approve a verified change' => [
        'approve',
        fn (string $workspace): Task => taskAwaitingHumanDecision($workspace),
        [],
        'molly:approve TASK --approve',
    ],
    'lock an authored Pest test' => [
        'lock_test',
        function (string $workspace): Task {
            $task = app(CreateTask::class)->handle('Author the greeting test.', $workspace, [], 'tests/GreetingTest.php', allowTestEdits: true);
            writeProtectedTest($workspace, contents: '<?php it("returns Hello", fn () => expect(true)->toBeTrue());');

            return $task;
        },
        ['paths' => ['app/Greeting.php'], 'reason' => 'Lock the authored greeting test.'],
        "molly:lock-test TASK --approve --file=app/Greeting.php --reason='Lock the authored greeting test.'",
    ],
    'record an opened pull request' => [
        'pr_opened',
        function (string $workspace): Task {
            $task = taskAwaitingHumanDecision($workspace);
            app(ApproveTask::class)->handle($task->id, true);

            return $task;
        },
        ['url' => 'https://github.com/sifrious/molly/pull/12'],
        'molly:pr-opened TASK --url=https://github.com/sifrious/molly/pull/12 --approve',
    ],
    'record a merge' => [
        'merged',
        function (string $workspace): Task {
            $task = taskAwaitingHumanDecision($workspace);
            app(ApproveTask::class)->handle($task->id, true);
            app(RecordPullRequestOpened::class)->handle($task->id, true, 'https://github.com/sifrious/molly/pull/12');

            return $task;
        },
        ['sha' => str_repeat('a', 40)],
        'molly:merged TASK --sha='.str_repeat('a', 40).' --approve',
    ],
    'post a GitHub issue comment' => [
        'comment',
        fn (string $workspace): Task => taskAwaitingHumanDecision($workspace, [
            'repository' => 'sifrious/molly',
            'issue_number' => 42,
            'issue_url' => 'https://github.com/sifrious/molly/issues/42',
        ]),
        ['close' => true],
        'molly:comment TASK --approve --close',
    ],
    'hand a task to another workspace' => [
        'handoff',
        function (string $workspace): Task {
            $task = app(CreateTask::class)->handle('Return Hello.', $workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
            $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => []]);

            return $task;
        },
        [
            'from_workspace_id' => '11111111-1111-4111-8111-111111111111',
            'to_workspace_id' => '22222222-2222-4222-8222-222222222222',
            'next_action' => 'implement',
            'context' => 'Implement the locked greeting test.',
        ],
        "molly:handoff TASK --from=11111111-1111-4111-8111-111111111111 --to=22222222-2222-4222-8222-222222222222 --action=implement --context='Implement the locked greeting test.' --approve",
    ],
]);

it('fills placeholders when the caller leaves out the pull request URL, merge SHA, or workspaces', function (string $operation, string $command) {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');

    MollyServer::tool(MollyTask::class, ['operation' => $operation, 'id' => $task->id])
        ->assertHasErrors(['HUMAN_APPROVAL_REQUIRED', 'Ask a person to run: php artisan '.str_replace('TASK', $task->id, $command)]);
})->with([
    ['pr_opened', 'molly:pr-opened TASK --url=URL --approve'],
    ['merged', 'molly:merged TASK --sha=SHA --approve'],
    ['handoff', 'molly:handoff TASK --from=UUID --to=UUID --approve'],
]);

it('keeps reading evidence over MCP after a person approves on the command line', function () {
    $task = taskAwaitingHumanDecision($this->workspace);
    app(ApproveTask::class)->handle($task->id, true);

    MollyServer::tool(MollyTask::class, ['operation' => 'show', 'id' => $task->id])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('display_status', DisplayStatus::Approved->value)->etc());
    MollyServer::tool(MollyTask::class, ['operation' => 'pr_body', 'id' => $task->id])->assertOk()
        ->assertSee('A human must approve opening or merging a pull request.');
});

it('refuses each human decision on the command line without --approve and changes nothing', function (string $command, array $options, string $code) {
    $task = taskAwaitingHumanDecision($this->workspace, [
        'repository' => 'sifrious/molly',
        'issue_number' => 42,
        'issue_url' => 'https://github.com/sifrious/molly/issues/42',
    ]);
    Process::fake();
    $before = humanDecisionState($task);

    expect(Artisan::call($command, ['task' => $task->id, ...$options, '--json' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'])->toStartWith($code.': ')
        ->and(humanDecisionState($task))->toBe($before)
        ->and(app(RecordLifecycleEvent::class)->load($this->workspace)->displayStatus($task->id))->toBe(DisplayStatus::AwaitingApproval);
    Process::assertNothingRan();
})->with([
    'approve' => ['molly:approve', [], 'APPROVAL_UNCONFIRMED'],
    'lock the test' => ['molly:lock-test', ['--file' => ['app/Greeting.php']], 'TEST_LOCK_UNCONFIRMED'],
    'record a pull request' => ['molly:pr-opened', ['--url' => 'https://github.com/sifrious/molly/pull/12'], 'PR_RECORD_UNCONFIRMED'],
    'record a merge' => ['molly:merged', ['--sha' => str_repeat('a', 40)], 'MERGE_RECORD_UNCONFIRMED'],
    'post a comment' => ['molly:comment', [], 'GITHUB_WRITEBACK_UNAPPROVED'],
    'hand off' => ['molly:handoff', ['--from' => '11111111-1111-4111-8111-111111111111', '--to' => '22222222-2222-4222-8222-222222222222'], 'HANDOFF_UNCONFIRMED'],
]);
