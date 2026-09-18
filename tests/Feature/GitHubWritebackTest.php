<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\ComposePullRequestBody;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\PublishGitHubIssueStatus;
use Sifrious\Molly\Console\MollyCommentCommand;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-writeback-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/routes');
    File::put($this->workspace.'/routes/web.php', '<?php');
    writeProtectedTest($this->workspace, 'tests/HealthTest.php');
    Process::preventStrayProcesses();
    $this->task = app(CreateTask::class)->handle(
        'Add a health route.',
        $this->workspace,
        ['routes/web.php'],
        'tests/HealthTest.php',
        [
            'repository' => 'sifrious/molly',
            'issue_number' => 42,
            'issue_url' => 'https://github.com/sifrious/molly/issues/42',
            'issue_digest' => hash('sha256', "Add a health route\nReturn JSON."),
            'linked_pr' => null,
        ],
    );
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('refuses a GitHub comment without explicit approval', function () {
    expect(fn () => app(PublishGitHubIssueStatus::class)->handle($this->task->id, false))
        ->toThrow(RuntimeException::class, 'GITHUB_WRITEBACK_UNAPPROVED');
    Process::assertNothingRan();
});

it('posts a concise GitHub comment after approval and keeps a later identical comment unchanged', function () {
    Process::fake(function (PendingProcess $process) {
        expect($process->command[0])->toBe('gh')
            ->and($process->command)->toContain('repos/sifrious/molly/issues/42/comments')
            ->and($process->command)->toContain('--input')
            ->and(implode("\n", $process->command))->not->toContain(test()->workspace)
            ->and(implode("\n", $process->command))->not->toContain('secret');

        return Process::result(output: json_encode([
            'id' => 99,
            'html_url' => 'https://github.com/sifrious/molly/issues/42#issuecomment-99',
        ]));
    });

    $first = app(PublishGitHubIssueStatus::class)->handle($this->task->id, true);
    $second = app(PublishGitHubIssueStatus::class)->handle($this->task->id, true);

    expect($first['comment_id'])->toBe(99)
        ->and($first['updated'])->toBeTrue()
        ->and($second['updated'])->toBeFalse()
        ->and($this->task->fresh()->source['github_comment_id'])->toBe(99);
    Process::assertRanTimes(fn (PendingProcess $process): bool => in_array('repos/sifrious/molly/issues/42/comments', $process->command, true), 1);
});

it('prints a pull request body that links the issue and acceptance test without local paths', function () {
    $this->task->update(['status' => 'completed']);
    $this->task->runs()->create([
        'prompt' => $this->task->prompt,
        'workspace' => $this->task->workspace,
        'status' => 'completed',
        'report' => [
            'verification' => ['status' => 'passed', 'tests' => 1],
            'verification_outcomes' => [
                'pest' => ['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry'],
                'tarpit' => ['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry'],
            ],
            'completion_blockers' => [],
        ],
    ]);

    $result = app(ComposePullRequestBody::class)->handle($this->task->id, true);

    expect($result['body'])->toContain('https://github.com/sifrious/molly/issues/42')
        ->and($result['body'])->toContain('tests/HealthTest.php')
        ->and($result['body'])->toContain('Closes #42')
        ->and($result['body'])->not->toContain($this->workspace)
        ->and($result['body'])->not->toContain($this->task->prompt);
});

it('keeps closing language off a failed task', function () {
    expect(fn () => app(ComposePullRequestBody::class)->handle($this->task->id, true))
        ->toThrow(RuntimeException::class, 'GITHUB_CLOSE_UNAVAILABLE');
});

it('returns JSON from the comment command only after approval', function () {
    Artisan::registerCommand(app(MollyCommentCommand::class));
    $exit = Artisan::call('molly:comment', ['task' => $this->task->id, '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($output['error'])->toStartWith('GITHUB_WRITEBACK_UNAPPROVED:');
});
