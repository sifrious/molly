<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Sifrious\Molly\Actions\GeneratePestTodos;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\TarpitReviewer;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;

const MOLLY_TODO_ISSUE = 'https://github.com/sifrious/molly-demo/issues/42';

function todoFixture(string $name): string
{
    return File::get(dirname(__DIR__).'/Fixtures/github/'.$name);
}

/** Fake only the gh call; Git and Pest still run for real. */
function fakeTodoIssue(?string $json = null): void
{
    Process::fake([
        "'gh' 'api' '--hostname' 'github.com' 'repos/sifrious/molly-demo/issues/42'" => Process::result(output: $json ?? todoFixture('issue-42.json')),
    ]);
}

/** @return array{0: int, 1: array<string, mixed>} */
function importTodos(array $options = []): array
{
    return mollyJson('molly:import', [
        'issue' => MOLLY_TODO_ISSUE,
        '--workspace' => test()->workspace,
        '--test' => 'tests/Feature/ReadyTest.php',
        '--file' => ['routes/web.php'],
        '--name' => 'ready-issue',
        '--allow-test-edits' => true,
        '--todos' => true,
        ...$options,
    ]);
}

/** @return list<array<string, mixed>> */
function cleanTodoReviews(int $count): array
{
    return array_fill(0, $count, ['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding in the selected files.']), 'findings' => []]);
}

/** Import issue 42 with todos, let an authoring run write the documented tests, and lock them. */
function lockReadyIssue(): void
{
    fakeTodoIssue();
    importTodos();
    ChangeWriter::fake([['summary' => 'Replace the todos with tests.', 'files' => [
        ['path' => 'tests/Feature/ReadyTest.php', 'content' => todoFixture('ReadyTest.authored.php')],
    ]]])->preventStrayPrompts();
    TarpitReviewer::fake(cleanTodoReviews(3))->preventStrayPrompts();
    test()->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'skipped', 'probes' => []]);
    mollyJson('molly:start', ['task' => 'ready-issue']);

    [$exit, $lock] = mollyJson('molly:lock-test', ['task' => 'ready-issue', '--approve' => true, '--file' => ['routes/web.php']]);
    expect($exit)->toBe(0, json_encode($lock));
}

beforeEach(function () {
    $this->workspace = laravelShapedWorkspace();
    config(['ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434'], 'molly.agent' => 'ollama']);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('reads the list under an acceptance criteria heading', function (string $body, array $criteria) {
    expect(app(GeneratePestTodos::class)->criteria($body))->toBe($criteria);
})->with([
    'checkboxes under a heading' => [json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/github/issue-42.json'), true)['body'], [
        'GET /ready returns HTTP 200.',
        'The response body is exactly {"ready":true}.',
        "A guest's request to GET /ready succeeds without signing in.",
    ]],
    'numbered list under bold text' => ["Intro\n\n**Acceptance Criteria**\n1. First.\n2) Second.\n\n# Other\n- Not a criterion.", ['First.', 'Second.']],
    'checked boxes and nested notes' => ["### Acceptance criteria\n* [x] Done already.\n    - nested detail\n+ Plain item.", ['Done already.', 'Plain item.']],
]);

it('refuses an issue without acceptance criteria or with too many', function (string $body, string $code) {
    expect(fn () => app(GeneratePestTodos::class)->criteria($body))->toThrow(RuntimeException::class, $code);
})->with([
    'no heading' => ['- GET /ready returns HTTP 200.', 'ISSUE_CRITERIA_MISSING'],
    'heading without a list' => ["## Acceptance criteria\n\nIt should work.", 'ISSUE_CRITERIA_MISSING'],
    'more than thirty' => ["## Acceptance criteria\n".implode("\n", array_map(fn (int $i): string => "- Criterion $i.", range(1, 31))), 'ISSUE_CRITERIA_INVALID'],
]);

it('writes the documented todo file and saves a test-authoring task', function () {
    $this->freezeTime();
    fakeTodoIssue();

    [$exit, $result] = importTodos();

    $task = Task::findOrFail($result['id']);
    expect($exit)->toBe(0)
        ->and(File::get($this->workspace.'/tests/Feature/ReadyTest.php'))->toBe(todoFixture('ReadyTest.todo.php'))
        ->and($task->allow_test_edits)->toBeTrue()
        ->and($task->paths)->toBe(['routes/web.php', 'tests/Feature/ReadyTest.php'])
        ->and($task->source['acceptance'])->toBe([
            'criteria' => [
                'GET /ready returns HTTP 200.',
                'The response body is exactly {"ready":true}.',
                "A guest's request to GET /ready succeeds without signing in.",
            ],
            'tests' => [
                'criterion 1: GET /ready returns HTTP 200.',
                'criterion 2: The response body is exactly {"ready":true}.',
                "criterion 3: A guest's request to GET /ready succeeds without signing in.",
            ],
            'todo_digest' => hash('sha256', todoFixture('ReadyTest.todo.php')),
        ])
        ->and($task->source['issue_number'])->toBe(42)
        ->and($task->prompt)->toContain('Replace every todo with an executable test', "1. GET /ready returns HTTP 200.\n2.", 'Leave no todo, skip, or incomplete test')
        ->and(Run::count())->toBe(0);
    Process::assertRanTimes(fn (PendingProcess $process): bool => $process->command[0] === 'gh', 1);
});

it('prints how many todos it wrote', function () {
    fakeTodoIssue();

    Artisan::call('molly:import', [
        'issue' => MOLLY_TODO_ISSUE, '--workspace' => $this->workspace, '--test' => 'tests/Feature/ReadyTest.php',
        '--file' => ['routes/web.php'], '--allow-test-edits' => true, '--todos' => true,
    ]);

    expect(preg_replace('/\s+/', ' ', Artisan::output()))->toContain('Wrote 3 Pest todos to tests/Feature/ReadyTest.php.');
});

it('requires --allow-test-edits before asking GitHub', function () {
    Process::fake();

    [$exit, $result] = importTodos(['--allow-test-edits' => false]);

    expect($exit)->toBe(1)
        ->and($result['error'])->toStartWith('TODOS_NEED_TEST_EDITS')
        ->and(File::exists($this->workspace.'/tests/Feature/ReadyTest.php'))->toBeFalse()
        ->and(Task::count())->toBe(0);
    Process::assertNothingRan();
});

it('never overwrites an existing test with todos', function () {
    File::put($this->workspace.'/tests/Feature/ReadyTest.php', "<?php it('keeps my test', fn () => expect(true)->toBeTrue());\n");
    fakeTodoIssue();

    [$exit, $result] = importTodos();

    expect($exit)->toBe(1)
        ->and($result['error'])->toStartWith('TODOS_TEST_EXISTS')
        ->and(File::get($this->workspace.'/tests/Feature/ReadyTest.php'))->toContain('keeps my test')
        ->and(Task::count())->toBe(0);
});

it('refuses an issue without acceptance criteria and writes nothing', function () {
    $issue = json_decode(todoFixture('issue-42.json'), true);
    $issue['body'] = 'Add a readiness route.';
    fakeTodoIssue(json_encode($issue));

    [$exit, $result] = importTodos();

    expect($exit)->toBe(1)
        ->and($result['error'])->toStartWith('ISSUE_CRITERIA_MISSING')
        ->and(File::exists($this->workspace.'/tests/Feature/ReadyTest.php'))->toBeFalse()
        ->and(Task::count())->toBe(0);
});

it('returns the same task when the issue is imported again', function () {
    fakeTodoIssue();

    [, $first] = importTodos();
    [$exit, $second] = importTodos();

    expect($exit)->toBe(0)
        ->and($second['id'])->toBe($first['id'])
        ->and(Task::count())->toBe(1)
        ->and(File::get($this->workspace.'/tests/Feature/ReadyTest.php'))->toBe(todoFixture('ReadyTest.todo.php'));
});

it('never counts the generated todos as passing tests', function () {
    File::put($this->workspace.'/tests/Feature/ReadyTest.php', todoFixture('ReadyTest.todo.php'));

    $result = app(VerifyChanges::class)->handle($this->workspace, 'tests/Feature/ReadyTest.php', $this->workspace.'/evidence');

    expect($result['status'])->toBe('failed', $result['output'])
        ->and($result['reason'])->toBe('tests_skipped_or_incomplete')
        ->and($result['skipped'])->toBe(3);
});

it('fails verification while any todo remains beside passing tests', function () {
    File::put($this->workspace.'/tests/Feature/ReadyTest.php', "<?php\n\nit('criterion 1: passes', fn () => expect(1 + 1)->toBe(2));\nit('criterion 2: not written yet')->todo();\n");

    $result = app(VerifyChanges::class)->handle($this->workspace, 'tests/Feature/ReadyTest.php', $this->workspace.'/evidence');

    expect($result['status'])->toBe('failed', $result['output'])
        ->and($result['reason'])->toBe('tests_skipped_or_incomplete');
});

it('refuses to lock todos, then locks the executable tests an authoring run writes', function () {
    fakeTodoIssue();
    [, $imported] = importTodos();

    [$exit, $refusal] = mollyJson('molly:lock-test', ['task' => 'ready-issue', '--approve' => true, '--file' => ['routes/web.php']]);

    expect($exit)->toBe(1)
        ->and($refusal['error'])->toStartWith('AUTHORED_TEST_BROKEN')
        ->and($refusal['authored_test']['causes'][0]['cause'])->toBe('tests_skipped')
        ->and(Task::findOrFail($imported['id'])->allow_test_edits)->toBeTrue();

    ChangeWriter::fake([['summary' => 'Replace the todos with tests.', 'files' => [
        ['path' => 'tests/Feature/ReadyTest.php', 'content' => todoFixture('ReadyTest.authored.php')],
    ]]])->preventStrayPrompts();
    TarpitReviewer::fake([['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding in the selected files.']), 'findings' => []]])->preventStrayPrompts();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'skipped', 'probes' => []]);

    [$startExit, $run] = mollyJson('molly:start', ['task' => 'ready-issue']);

    ChangeWriter::assertPrompted(function ($prompt): bool {
        $payload = json_decode($prompt->prompt, true);

        return $payload['allowed_files']['tests/Feature/ReadyTest.php'] === todoFixture('ReadyTest.todo.php')
            && $payload['protected_test']['writable'] === true;
    });
    expect($startExit)->toBe(1)
        ->and($run['report']['verification']['status'])->toBe('failed')
        ->and(File::get($this->workspace.'/tests/Feature/ReadyTest.php'))->toBe(todoFixture('ReadyTest.authored.php'));

    [$lockExit, $lock] = mollyJson('molly:lock-test', ['task' => 'ready-issue', '--approve' => true, '--file' => ['routes/web.php']]);

    expect($lockExit)->toBe(0)
        ->and($lock['locked'])->toBeTrue()
        ->and($lock['red_baseline']['classification'])->toBe('missing_behavior')
        ->and($lock['paths'])->toBe(['routes/web.php'])
        ->and(Task::findOrFail($imported['id'])->allow_test_edits)->toBeFalse();
});

it('refuses to lock an authored file that keeps a todo beside failing tests', function () {
    fakeTodoIssue();
    [, $imported] = importTodos();
    ChangeWriter::fake([['summary' => 'Write the first test.', 'files' => [
        ['path' => 'tests/Feature/ReadyTest.php', 'content' => "<?php\n\nit('criterion 1: GET /ready returns HTTP 200.', function () {\n    \$this->get('/ready')->assertOk();\n});\n\nit('criterion 2: The response body is exactly {\"ready\":true}.')->todo();\n"],
    ]]])->preventStrayPrompts();
    TarpitReviewer::fake(cleanTodoReviews(1))->preventStrayPrompts();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'skipped', 'probes' => []]);

    [, $run] = mollyJson('molly:start', ['task' => 'ready-issue']);
    [$exit, $refusal] = mollyJson('molly:lock-test', ['task' => 'ready-issue', '--approve' => true, '--file' => ['routes/web.php']]);

    expect($run['report']['verification'])->toMatchArray(['failures' => 1, 'skipped' => 1])
        ->and($run['report']['authored_test']['test_broken'])->toBeTrue()
        ->and(array_column($run['report']['authored_test']['causes'], 'cause'))->toBe(['tests_skipped'])
        ->and($exit)->toBe(1)
        ->and($refusal['error'])->toStartWith('AUTHORED_TEST_BROKEN')
        ->and(array_column($refusal['authored_test']['causes'], 'cause'))->toBe(['tests_skipped'])
        ->and(Task::findOrFail($imported['id'])->allow_test_edits)->toBeTrue();
});

it('protects the locked test, then completes only when every criterion test passes', function () {
    lockReadyIssue();
    ChangeWriter::fake([
        ['summary' => 'Loosen the tests.', 'files' => [
            ['path' => 'routes/web.php', 'content' => readyRoute()],
            ['path' => 'tests/Feature/ReadyTest.php', 'content' => "<?php\n\nit('criterion 1: passes', fn () => expect(true)->toBeTrue());\n"],
        ]],
        ['summary' => 'Add the readiness route.', 'files' => [
            ['path' => 'routes/web.php', 'content' => readyRoute()],
        ]],
    ])->preventStrayPrompts();

    [$rejectedExit, $rejected] = mollyJson('molly:start', ['task' => 'ready-issue']);

    expect($rejectedExit)->toBe(1)
        ->and(json_encode($rejected))->toContain('PROTECTED_TEST_CHANGED')
        ->and(File::get($this->workspace.'/tests/Feature/ReadyTest.php'))->toBe(todoFixture('ReadyTest.authored.php'))
        ->and(File::get($this->workspace.'/routes/web.php'))->toBe('<?php');

    [$exit, $run] = mollyJson('molly:retry', ['task' => 'ready-issue']);

    expect($exit)->toBe(0, json_encode($run['report'] ?? $run))
        ->and($run['status'])->toBe('completed')
        ->and($run['report']['verification'])->toMatchArray(['status' => 'passed', 'tests' => 3, 'failures' => 0, 'skipped' => 0])
        ->and(File::get($this->workspace.'/tests/Feature/ReadyTest.php'))->toBe(todoFixture('ReadyTest.authored.php'));
});

it('never completes a run while the required test keeps a todo, skipped, or incomplete test', function (string $pending) {
    File::put($this->workspace.'/tests/Feature/ReadyTest.php', "<?php\n\nit('criterion 1: GET /ready returns HTTP 200.', function () {\n    \$this->get('/ready')->assertOk();\n});\n\n".$pending."\n");
    [$created, $task] = mollyJson('molly:create', [
        'prompt' => 'Add GET /ready returning exactly {"ready":true}.',
        '--workspace' => $this->workspace,
        '--name' => 'ready-pending',
        '--test' => 'tests/Feature/ReadyTest.php',
        '--file' => ['routes/web.php'],
    ]);
    ChangeWriter::fake([['summary' => 'Add the readiness route.', 'files' => [
        ['path' => 'routes/web.php', 'content' => readyRoute()],
    ]]])->preventStrayPrompts();
    TarpitReviewer::fake(cleanTodoReviews(1))->preventStrayPrompts();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'skipped', 'probes' => []]);

    [$exit, $run] = mollyJson('molly:start', ['task' => 'ready-pending']);

    expect($created)->toBe(0, json_encode($task))
        ->and($exit)->toBe(1)
        ->and($run['status'])->toBe('failed')
        ->and($run['report']['verification'])->toMatchArray(['status' => 'failed', 'reason' => 'tests_skipped_or_incomplete', 'failures' => 0, 'errors' => 0, 'skipped' => 1])
        ->and($run['report']['completion_blockers'])->toBe(['pest'])
        ->and(File::get($this->workspace.'/routes/web.php'))->toBe(readyRoute());
})->with([
    'a todo' => ["it('criterion 2: The response body is exactly {\"ready\":true}.')->todo();"],
    'a skipped test' => ["it('criterion 2: The response body is exactly {\"ready\":true}.', fn () => expect(true)->toBeTrue())->skip('Not written yet.');"],
    'an incomplete test' => ["it('criterion 2: The response body is exactly {\"ready\":true}.', function () {\n    \$this->markTestIncomplete('Not written yet.');\n});"],
]);

it('keeps the documented issue, todo file, and authored tests identical to the fixtures', function () {
    $documented = File::get(dirname(__DIR__, 2).'/docs/github-todos.md');
    $issue = json_decode(todoFixture('issue-42.json'), true);

    expect($documented)->toContain(trim($issue['body']))
        ->toContain(trim(todoFixture('ReadyTest.todo.php')))
        ->toContain(trim(todoFixture('ReadyTest.authored.php')));
});
