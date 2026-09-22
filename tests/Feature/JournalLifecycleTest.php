<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\ImportGitHubIssue;
use Sifrious\Molly\Actions\NameTask;
use Sifrious\Molly\Actions\RefreshProjectJournal;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Actions\StopTask;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;

beforeEach(function (): void {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'session.driver' => 'array',
        'molly.ui.enabled' => true,
    ]);
});

afterEach(function (): void {
    if (isset($this->journalWorkspace)) {
        if (is_dir($this->journalWorkspace.'/.molly')) {
            chmod($this->journalWorkspace.'/.molly', 0700);
        }
        File::deleteDirectory($this->journalWorkspace);
    }
});

function lifecycleWorkspace(): string
{
    $path = sys_get_temp_dir().'/molly-journal-lifecycle-'.Str::uuid();
    File::ensureDirectoryExists($path);
    writeProtectedTest($path, 'tests/StatusTest.php');
    writeProtectedTest($path, 'tests/HealthTest.php');

    return test()->journalWorkspace = realpath($path);
}

function lifecycleTask(): Task
{
    return app(CreateTask::class)->handle('Test the current status.', lifecycleWorkspace(), [], 'tests/StatusTest.php', nickname: 'status-test', allowTestEdits: true);
}

it('refreshes the project journal for created and imported tasks without starting work', function (): void {
    $this->freezeTime();
    $task = lifecycleTask();
    Process::preventStrayProcesses();
    Process::fake(["'gh' 'api' '--hostname' 'github.com' 'repos/sifrious/molly/issues/42'" => Process::result(output: json_encode([
        'html_url' => 'https://github.com/sifrious/molly/issues/42', 'number' => 42,
        'title' => 'Test the health endpoint.', 'body' => 'Check the healthy response.',
        'updated_at' => '2026-09-17T12:00:00Z', 'labels' => [],
    ]))]);

    $imported = app(ImportGitHubIssue::class)->handle('https://github.com/sifrious/molly/issues/42', $this->journalWorkspace, [], 'tests/HealthTest.php', allowTestEdits: true);
    $journal = Str::markdown(File::get($task->journal_status['journal_path']));

    expect($task->journal_status)->toBe([
        'status' => 'written',
        'journal_path' => $this->journalWorkspace.'/.molly/JOURNAL.md',
        'glossary_path' => $this->journalWorkspace.'/.molly/GLOSSARY.md',
        'checked_at' => now()->toIso8601String(),
    ])
        ->and($imported->journal_status['status'])->toBe('written')
        ->and($journal)->toContain($task->id, $imported->id, 'Test the health endpoint.', 'Status: pending')
        ->and(substr_count($journal, '<h2>Task created</h2>'))->toBe(2)
        ->and(File::exists($this->journalWorkspace.'/tests/StatusTest.php'))->toBeTrue()
        ->and(File::exists($this->journalWorkspace.'/tests/HealthTest.php'))->toBeTrue()
        ->and(Run::count())->toBe(0);
    Process::assertRanTimes(fn (PendingProcess $process): bool => $process->command === ['gh', 'api', '--hostname', 'github.com', 'repos/sifrious/molly/issues/42'], 1);
});

it('refreshes after a nickname change and replays without changing lifecycle timestamps or duplicating entries', function (): void {
    $this->freezeTime();
    $task = lifecycleTask();
    $this->travel(1)->minute();

    $renamed = app(NameTask::class)->handle($task->id, 'renamed-test');
    $journal = File::get($renamed->journal_status['journal_path']);
    $updatedAt = $renamed->updated_at;
    $this->travel(1)->minute();
    $refreshed = app(RefreshProjectJournal::class)->handle($renamed);

    expect(Str::markdown($journal))->toContain('renamed-test')->not->toContain('status-test')
        ->and(File::get($refreshed->journal_status['journal_path']))->toBe($journal)
        ->and(substr_count(Str::markdown($journal), $task->id))->toBe(1)
        ->and($refreshed->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and($refreshed->journal_status['checked_at'])->toBe(now()->toIso8601String())
        ->and($refreshed->status)->toBe('pending')
        ->and(Run::count())->toBe(0);
});

it('refreshes the running claim and final attempt outcome', function (string $status): void {
    $task = lifecycleTask();
    $this->mock(RunTask::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($task, $status): Run {
        expect($task->fresh()->status)->toBe('running')
            ->and(Str::markdown(File::get($this->journalWorkspace.'/.molly/JOURNAL.md')))->toContain('Status: running');

        return $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => $status, 'report' => ['summary' => 'Recorded the attempt outcome.']]);
    });

    $run = app(StartTask::class)->handle($task->id);
    $journal = Str::markdown(File::get($this->journalWorkspace.'/.molly/JOURNAL.md'));

    expect($run->status)->toBe($status)
        ->and($task->fresh()->status)->toBe($status)
        ->and($task->fresh()->journal_status['status'])->toBe('written')
        ->and($journal)->toContain($run->id, 'Status: '.$status, 'Recorded the attempt outcome.')
        ->and(substr_count($journal, $run->id))->toBe(1);
})->with(['completed', 'failed']);

it('refreshes the failed claim when execution throws before creating an attempt', function (): void {
    $task = lifecycleTask();
    $this->mock(RunTask::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('WORKSPACE_BUSY: Another run owns the workspace.'));

    expect(fn () => app(StartTask::class)->handle($task->id))->toThrow(RuntimeException::class, 'WORKSPACE_BUSY');

    expect($task->fresh()->status)->toBe('failed')
        ->and($task->fresh()->journal_status['status'])->toBe('written')
        ->and(Str::markdown(File::get($this->journalWorkspace.'/.molly/JOURNAL.md')))->toContain('Status: failed')
        ->and(Run::count())->toBe(0);
});

it('refreshes a pending stop and keeps repeated terminal stops unchanged', function (): void {
    $this->freezeTime();
    $task = lifecycleTask();

    $stopped = app(StopTask::class)->handle($task->id);
    $before = $stopped->getRawOriginal();
    $journal = File::get($this->journalWorkspace.'/.molly/JOURNAL.md');
    $this->travel(1)->minute();
    $repeated = app(StopTask::class)->handle($task->id);

    expect($stopped->status)->toBe('stopped')
        ->and($stopped->journal_status['status'])->toBe('written')
        ->and(Str::markdown($journal))->toContain('Status: stopped')
        ->and($repeated->getRawOriginal())->toBe($before)
        ->and(File::get($this->journalWorkspace.'/.molly/JOURNAL.md'))->toBe($journal)
        ->and(Run::count())->toBe(0);
});

it('refreshes stop requests and interrupted attempts while preserving prior evidence', function (): void {
    $task = lifecycleTask();
    $task->update(['status' => 'running']);
    $run = $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'running', 'report' => ['summary' => 'Keep the completed check evidence.']]);
    $workspace = new Workspace($task->workspace);

    $requested = $workspace->exclusivelyForTask($task->id, fn (): Task => app(StopTask::class)->handle($task->id));
    expect($requested->status)->toBe('running')
        ->and($requested->stop_requested_at)->not->toBeNull()
        ->and($requested->journal_status['status'])->toBe('written')
        ->and(Str::markdown(File::get($this->journalWorkspace.'/.molly/JOURNAL.md')))->toContain($run->id, 'Status: running');

    $stopped = app(StopTask::class)->handle($task->id);

    expect($stopped->status)->toBe('stopped')
        ->and($run->fresh()->status)->toBe('stopped')
        ->and($stopped->journal_status['status'])->toBe('written')
        ->and(Str::markdown(File::get($this->journalWorkspace.'/.molly/JOURNAL.md')))->toContain('Status: stopped', 'RUN_INTERRUPTED', 'Keep the completed check evidence.');
});

it('keeps a newly saved task when a linked project journal cannot be written', function (): void {
    $workspace = lifecycleWorkspace();
    File::ensureDirectoryExists($workspace.'/.molly');
    File::put($workspace.'/untouched.md', 'Keep this file.');
    symlink($workspace.'/untouched.md', $workspace.'/.molly/JOURNAL.md');

    try {
        writeProtectedTest($workspace, 'tests/StatusTest.php');
        $task = app(CreateTask::class)->handle('Test the current status.', $workspace, [], 'tests/StatusTest.php', allowTestEdits: true);

        expect($task->fresh()->status)->toBe('pending')
            ->and($task->journal_status['status'])->toBe('unavailable')
            ->and($task->journal_status['reason'])->toStartWith('JOURNAL_PATH_INVALID:')
            ->and($task->journal_status)->not->toHaveKeys(['journal_path', 'glossary_path'])
            ->and(File::get($workspace.'/untouched.md'))->toBe('Keep this file.')
            ->and(Run::count())->toBe(0);
    } finally {
        unlink($workspace.'/.molly/JOURNAL.md');
    }
});

it('preserves a completed task when its journal directory becomes read only', function (): void {
    $task = lifecycleTask();
    $this->mock(RunTask::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($task): Run {
        chmod($this->journalWorkspace.'/.molly', 0500);

        return $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'completed', 'report' => ['summary' => 'The task completed.']]);
    });

    try {
        $run = app(StartTask::class)->handle($task->id);

        expect($run->status)->toBe('completed')
            ->and($task->fresh()->status)->toBe('completed')
            ->and($task->fresh()->journal_status['status'])->toBe('unavailable')
            ->and($task->fresh()->journal_status['reason'])->toStartWith('JOURNAL_WRITE_FAILED:')
            ->and(Str::markdown(File::get($this->journalWorkspace.'/.molly/JOURNAL.md')))->toContain('Status: running');
    } finally {
        chmod($this->journalWorkspace.'/.molly', 0700);
    }

    $this->get('/molly/tasks/'.$task->id)->assertOk()->assertSee('Project journal unavailable.')->assertSee('The journal warning does not change the task result.')->assertSee('php artisan molly:journal status-test --project')->assertSee('Find linked Amp threads');
    Artisan::call('molly:task', ['task' => $task->id]);
    expect(Artisan::output())->toContain('completed', 'Project journal unavailable.', 'php artisan molly:journal status-test --project');
});

it('retries a failed project refresh through the command and clears the warning', function (): void {
    $task = lifecycleTask();
    $originalUpdatedAt = $task->updated_at;
    File::delete($this->journalWorkspace.'/.molly/JOURNAL.md');
    File::makeDirectory($this->journalWorkspace.'/.molly/JOURNAL.md');

    $failed = Artisan::call('molly:journal', ['task' => 'status-test', '--project' => true, '--json' => true]);
    $failure = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($failed)->toBe(1)
        ->and($failure['status'])->toBe('unavailable')
        ->and($failure['task_id'])->toBe($task->id)
        ->and($task->fresh()->status)->toBe('pending')
        ->and($task->fresh()->journal_status['status'])->toBe('unavailable');
    File::deleteDirectory($this->journalWorkspace.'/.molly/JOURNAL.md');

    $exit = Artisan::call('molly:journal', ['task' => $task->id, '--project' => true, '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($result['status'])->toBe('written')
        ->and($result['task_id'])->toBe($task->id)
        ->and($task->fresh()->journal_status)->not->toHaveKey('reason')
        ->and($task->fresh()->updated_at->equalTo($originalUpdatedAt))->toBeTrue()
        ->and(Str::markdown(File::get($result['journal_path'])))->toContain($task->id, 'Status: pending');
    $this->get('/molly/tasks/'.$task->id)->assertOk()->assertSee('Project journal refreshed at')->assertDontSee('Project journal unavailable.');
});

it('leaves unrelated tasks unchanged when a project refresh names an unknown task', function (): void {
    $task = lifecycleTask();
    $before = $task->fresh()->getRawOriginal();
    $journal = File::get($task->journal_status['journal_path']);

    $exit = Artisan::call('molly:journal', ['task' => 'missing-task', '--project' => true, '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($result['error'])->toStartWith('TASK_NOT_FOUND:')
        ->and($task->fresh()->getRawOriginal())->toBe($before)
        ->and(File::get($task->journal_status['journal_path']))->toBe($journal);
});

it('proves lifecycle journal refresh stays byte-compatible with private permissions', function (): void {
    $task = lifecycleTask();
    // First refresh stabilizes lifecycle summary lines written after create.
    $task = app(RefreshProjectJournal::class)->handle($task->fresh());
    $journalPath = $task->journal_status['journal_path'];
    $glossaryPath = $task->journal_status['glossary_path'];
    $original = File::get($journalPath);
    $glossary = File::get($glossaryPath);
    $before = $task->fresh()->getRawOriginal();
    unset($before['journal_status']);
    $checkedAt = $task->journal_status['checked_at'];

    $this->travel(1)->minute();
    $refreshed = app(RefreshProjectJournal::class)->handle($task->fresh());
    $after = $refreshed->fresh()->getRawOriginal();
    unset($after['journal_status']);

    expect(File::get($journalPath))->toBe($original)
        ->and(File::get($glossaryPath))->toBe($glossary)
        ->and($refreshed->journal_status['status'])->toBe('written')
        ->and($refreshed->journal_status['journal_path'])->toBe($journalPath)
        ->and($refreshed->journal_status['glossary_path'])->toBe($glossaryPath)
        ->and($refreshed->journal_status['checked_at'])->not->toBe($checkedAt)
        ->and(fileperms($this->journalWorkspace.'/.molly') & 0777)->toBe(0700)
        ->and(fileperms($journalPath) & 0777)->toBe(0600)
        ->and(fileperms($glossaryPath) & 0777)->toBe(0600)
        ->and(fileperms($this->journalWorkspace.'/.molly/.gitignore') & 0777)->toBe(0600)
        ->and(scandir($this->journalWorkspace.'/.molly'))->toContain('.gitignore', 'GLOSSARY.md', 'JOURNAL.md')
        ->and(collect(scandir($this->journalWorkspace.'/.molly'))->filter(fn ($name) => str_ends_with($name, '.tmp'))->all())->toBe([])
        ->and($glossary)->toContain('<!-- molly:glossary:start -->', '<!-- molly:glossary:end -->')
        ->and($after)->toBe($before)
        ->and($refreshed->status)->toBe('pending');
});
