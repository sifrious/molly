<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\ListTasks;
use Sifrious\Molly\Actions\RetryTask;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Actions\ShowTask;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Actions\StopTask;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-records-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

function createMollyRecord(): Task
{
    return app(CreateTask::class)->handle('Return Hello.', test()->workspace, ['app/Greeting.php', 'tests/GreetingTest.php'], 'tests/GreetingTest.php');
}

it('stores a pending task without editing files or starting a run', function () {
    ChangeWriter::fake()->preventStrayPrompts();
    $source = ['provider' => 'github', 'repository' => 'sifrious/molly', 'number' => 42];

    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace.'/.', ['app/Greeting.php', 'tests/GreetingTest.php'], 'tests/GreetingTest.php', $source)->fresh();

    expect(Str::isUuid($task->id))->toBeTrue()
        ->and($task->status)->toBe('pending')
        ->and($task->prompt)->toBe('Return Hello.')
        ->and($task->workspace)->toBe(realpath($this->workspace))
        ->and($task->paths)->toBe(['app/Greeting.php', 'tests/GreetingTest.php'])
        ->and($task->test_path)->toBe('tests/GreetingTest.php')
        ->and($task->source)->toBe($source)
        ->and($task->stop_requested_at)->toBeNull()
        ->and(Run::count())->toBe(0)
        ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe('<?php return null;')
        ->and(File::exists($this->workspace.'/tests/GreetingTest.php'))->toBeFalse()
        ->and(File::exists($this->workspace.'/.molly/JOURNAL.md'))->toBeTrue();
    ChangeWriter::assertNeverPrompted();
});

it('rejects invalid task scope before saving a task', function (string $prompt, array $paths, string $testPath, string $error) {
    expect(fn () => app(CreateTask::class)->handle($prompt, $this->workspace, $paths, $testPath))
        ->toThrow(RuntimeException::class, $error);
    expect(Task::count())->toBe(0);
})->with([
    'blank prompt' => [' ', ['tests/Hello.php'], 'tests/Hello.php', 'PROMPT_INVALID'],
    'long prompt' => [str_repeat('a', 8193), ['tests/Hello.php'], 'tests/Hello.php', 'PROMPT_INVALID'],
    'missing test' => ['Hello', ['app/Hello.php'], '', 'TEST_PATH_INVALID'],
    'non-test file' => ['Hello', ['app/Hello.php'], 'app/Hello.php', 'TEST_PATH_INVALID'],
    'non-PHP test' => ['Hello', ['tests/Hello.txt'], 'tests/Hello.txt', 'TEST_PATH_INVALID'],
    'duplicate paths' => ['Hello', ['tests/Hello.php', 'tests/Hello.php'], 'tests/Hello.php', 'FILES_INVALID'],
    'path traversal' => ['Hello', ['tests/../Hello.php'], 'tests/../Hello.php', 'PATH_INVALID'],
    'non-string path' => ['Hello', ['tests/Hello.php', 42], 'tests/Hello.php', 'FILES_INVALID'],
    'associative paths' => ['Hello', ['test' => 'tests/Hello.php'], 'tests/Hello.php', 'FILES_INVALID'],
]);

it('rejects an invalid workspace before saving a task', function () {
    expect(fn () => app(CreateTask::class)->handle('Hello', $this->workspace.'/missing', ['tests/Hello.php'], 'tests/Hello.php'))
        ->toThrow(RuntimeException::class, 'WORKSPACE_INVALID');
    expect(Task::count())->toBe(0);
});

it('rejects source metadata that cannot be stored as JSON', function () {
    expect(fn () => app(CreateTask::class)->handle('Hello', $this->workspace, ['tests/Hello.php'], 'tests/Hello.php', ['number' => INF]))
        ->toThrow(RuntimeException::class, 'SOURCE_INVALID');
    expect(Task::count())->toBe(0);
});

it('loads attempts oldest first and preserves standalone runs', function () {
    $task = createMollyRecord();
    $later = $task->runs()->create(['prompt' => 'Hello', 'workspace' => $this->workspace, 'status' => 'failed', 'report' => []]);
    $earlier = $task->runs()->create(['prompt' => 'Hello', 'workspace' => $this->workspace, 'status' => 'completed', 'report' => []]);
    $earlier->forceFill(['created_at' => now()->subMinute()])->save();
    $standalone = Run::create(['prompt' => 'Old task', 'workspace' => $this->workspace, 'status' => 'completed', 'report' => []]);

    $found = app(ShowTask::class)->handle($task->id);

    expect($found->relationLoaded('runs'))->toBeTrue()
        ->and($found->runs->modelKeys())->toBe([$earlier->id, $later->id])
        ->and($earlier->task->id)->toBe($task->id)
        ->and($standalone->fresh()->task)->toBeNull()
        ->and(app(ShowTask::class)->handle((string) Str::uuid()))->toBeNull();
});

it('lists recent tasks within the requested limit', function () {
    $older = createMollyRecord();
    $older->forceFill(['created_at' => now()->subMinute()])->save();
    $recent = createMollyRecord();

    expect(app(ListTasks::class)->handle(1)->modelKeys())->toBe([$recent->id])
        ->and(app(ListTasks::class)->handle()->modelKeys())->toBe([$recent->id, $older->id]);
});

it('rejects list limits outside the supported range', function (int $limit) {
    expect(fn () => app(ListTasks::class)->handle($limit))->toThrow(RuntimeException::class, 'TASK_LIMIT_INVALID');
})->with([0, -1, 101]);

it('stops pending tasks without creating an attempt', function () {
    $this->travelTo(now()->startOfSecond());
    $task = createMollyRecord();

    $stopped = app(StopTask::class)->handle($task->id);

    expect($stopped->status)->toBe('stopped')
        ->and($stopped->stop_requested_at->equalTo(now()))->toBeTrue()
        ->and($task->fresh()->status)->toBe('stopped')
        ->and($task->runs()->count())->toBe(0);
});

it('requests a running task stop only once without changing the attempt', function () {
    $this->travelTo(now()->startOfSecond());
    $task = createMollyRecord();
    $task->update(['status' => 'running']);
    $run = $task->runs()->create(['prompt' => 'Hello', 'workspace' => $this->workspace, 'status' => 'running', 'report' => []]);
    $requestedAt = now()->copy();
    $stopped = (new Workspace($this->workspace))->exclusively(function () use ($task): Task {
        app(StopTask::class)->handle($task->id);
        $this->travel(1)->minute();

        return app(StopTask::class)->handle($task->id);
    });

    expect($stopped->status)->toBe('running')
        ->and($stopped->stop_requested_at->equalTo($requestedAt))->toBeTrue()
        ->and($run->fresh()->status)->toBe('running');
});

it('leaves terminal tasks unchanged when asked to stop', function (string $status) {
    $task = createMollyRecord();
    $task->update(['status' => $status]);
    $before = $task->fresh()->getAttributes();

    $stopped = app(StopTask::class)->handle($task->id);

    expect($stopped->getAttributes())->toBe($before);
})->with(['failed', 'completed', 'stopped']);

it('reports an unknown task when asked to stop', function () {
    expect(fn () => app(StopTask::class)->handle((string) Str::uuid()))
        ->toThrow(RuntimeException::class, 'TASK_NOT_FOUND');
});

it('settles an interrupted attempt when the workspace lock is free and preserves evidence', function () {
    $task = createMollyRecord();
    $task->update(['status' => 'running']);
    $report = ['verification' => ['status' => 'passed', 'tests' => 2], 'changes' => [['path' => 'app/Greeting.php']]];
    $run = $task->runs()->create(['prompt' => 'Hello', 'workspace' => $this->workspace, 'status' => 'running', 'report' => $report]);
    $previous = $task->runs()->create(['prompt' => 'Hello', 'workspace' => $this->workspace, 'status' => 'failed', 'report' => ['error' => 'Earlier failure']]);

    $stopped = app(StopTask::class)->handle($task->id);

    expect($stopped->status)->toBe('stopped')
        ->and($stopped->stop_requested_at)->not->toBeNull()
        ->and($run->fresh()->status)->toBe('stopped')
        ->and($run->fresh()->report)->toBe([...$report, 'error' => 'RUN_INTERRUPTED: The original process no longer holds the workspace lock. Saved evidence remains available.'])
        ->and($previous->fresh()->status)->toBe('failed')
        ->and($previous->fresh()->report)->toBe(['error' => 'Earlier failure'])
        ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe('<?php return null;');
});

it('stops a task that claimed execution but has not created an attempt', function () {
    $task = createMollyRecord();
    $task->update(['status' => 'running']);

    $stopped = app(StopTask::class)->handle($task->id);

    expect($stopped->status)->toBe('stopped')
        ->and($stopped->stop_requested_at)->not->toBeNull()
        ->and($task->runs()->count())->toBe(0);
});

it('keeps a stop requested when the workspace no longer exists', function () {
    $task = createMollyRecord();
    $task->update(['status' => 'running']);
    File::deleteDirectory($this->workspace);

    expect(fn () => app(StopTask::class)->handle($task->id))
        ->toThrow(RuntimeException::class, 'WORKSPACE_INVALID');
    expect($task->fresh()->status)->toBe('running')
        ->and($task->fresh()->stop_requested_at)->not->toBeNull();
});

it('does not mistake an invalid lock for an active writer', function () {
    $task = createMollyRecord();
    $task->update(['status' => 'running']);
    File::deleteDirectory($this->workspace.'/.molly');
    File::put($this->workspace.'/.molly', 'not a directory');

    expect(fn () => app(StopTask::class)->handle($task->id))
        ->toThrow(RuntimeException::class, 'WORKSPACE_LOCK_INVALID');
    expect($task->fresh()->status)->toBe('running')
        ->and($task->fresh()->stop_requested_at)->not->toBeNull();
});

it('keeps the task claim locked before workspace execution and through finalization', function (bool $beforeExecution) {
    $task = createMollyRecord();
    $executor = Mockery::mock(RunTask::class);
    $executor->shouldReceive('handle')->once()->andReturnUsing(function (string $prompt, string $workspace, array $paths, string $testPath, ?Closure $progress, string $taskId, Closure $shouldStop) use ($task, $beforeExecution): Run {
        $run = null;
        if (! $beforeExecution) {
            $run = $task->runs()->create(['prompt' => $prompt, 'workspace' => $workspace, 'status' => 'completed', 'report' => ['summary' => 'Finished the edit.']]);
        }
        $stopping = app(StopTask::class)->handle($taskId);
        expect($stopping->status)->toBe('running')
            ->and($stopping->stop_requested_at)->not->toBeNull()
            ->and($shouldStop())->toBeTrue();
        expect(fn () => app(RetryTask::class)->handle($taskId))
            ->toThrow(RuntimeException::class, 'WORKSPACE_BUSY');
        expect($task->fresh()->stop_requested_at)->not->toBeNull();

        return $run ?? $task->runs()->create(['prompt' => $prompt, 'workspace' => $workspace, 'status' => 'stopped', 'report' => []]);
    });
    $this->app->instance(RunTask::class, $executor);

    $run = app(StartTask::class)->handle($task->id);

    expect($run->status)->toBe('stopped')
        ->and($task->fresh()->status)->toBe('stopped')
        ->and($task->runs()->count())->toBe(1);
    expect((new Workspace($this->workspace))->exclusivelyForTask($task->id, fn (): string => 'released'))->toBe('released');
})->with([true, false]);

it('preserves a stopped task when retry exceeds the attempt limit', function () {
    $task = createMollyRecord();
    $task->update(['status' => 'stopped', 'stop_requested_at' => now()->startOfSecond()]);
    $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'stopped', 'report' => []]);
    $before = $task->fresh()->getAttributes();
    config()->set('molly.max_attempts', 1);

    expect(fn () => app(RetryTask::class)->handle($task->id))
        ->toThrow(RuntimeException::class, 'ATTEMPT_LIMIT_REACHED');

    expect($task->fresh()->getAttributes())->toBe($before)
        ->and($task->runs()->count())->toBe(1);
});

it('rejects unsafe task lock names without creating files', function () {
    expect(fn () => (new Workspace($this->workspace))->exclusivelyForTask('../outside', fn (): bool => true))
        ->toThrow(RuntimeException::class, 'TASK_ID_INVALID');
    expect(File::exists($this->workspace.'/.molly'))->toBeFalse();
});

it('releases the task lock when execution throws', function () {
    $task = createMollyRecord();
    $executor = Mockery::mock(RunTask::class);
    $executor->shouldReceive('handle')->once()->andThrow(new RuntimeException('WORKSPACE_BUSY: Another Molly run is using this project.'));
    $this->app->instance(RunTask::class, $executor);

    expect(fn () => app(StartTask::class)->handle($task->id))
        ->toThrow(RuntimeException::class, 'WORKSPACE_BUSY');

    expect($task->fresh()->status)->toBe('failed')
        ->and((new Workspace($this->workspace))->exclusivelyForTask($task->id, fn (): string => 'released'))->toBe('released');
});

it('uses one task lock for differently cased UUIDs', function () {
    $task = createMollyRecord();
    $workspace = new Workspace($this->workspace);

    $workspace->exclusivelyForTask($task->id, function () use ($task, $workspace): void {
        expect(fn () => $workspace->exclusivelyForTask(strtoupper($task->id), fn (): bool => true))
            ->toThrow(RuntimeException::class, 'WORKSPACE_BUSY');
    });
});

it('guides task creation and uses the nickname in the start command', function (): void {
    ChangeWriter::fake()->preventStrayPrompts();

    $this->artisan('molly:create', ['--workspace' => $this->workspace])
        ->expectsQuestion('What should Molly work on?', 'Return Hello.')
        ->expectsQuestion('Task nickname', 'greeting')
        ->expectsQuestion('Which Pest test should pass?', 'tests/GreetingTest.php')
        ->expectsQuestion('Which other files may Molly change?', 'app/Greeting.php, routes/web.php')
        ->expectsOutputToContain('php artisan molly:start greeting')
        ->assertSuccessful();

    $task = Task::sole();
    expect($task->nickname)->toBe('greeting')
        ->and($task->paths)->toBe(['app/Greeting.php', 'routes/web.php', 'tests/GreetingTest.php'])
        ->and($task->test_path)->toBe('tests/GreetingTest.php')
        ->and($task->status)->toBe('pending')
        ->and(Run::count())->toBe(0)
        ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe('<?php return null;')
        ->and(File::exists($this->workspace.'/tests/GreetingTest.php'))->toBeFalse();
    ChangeWriter::assertNeverPrompted();
});

it('allows an unnamed guided task that changes only its test', function (): void {
    $this->artisan('molly:create', ['prompt' => 'Test the greeting.', '--workspace' => $this->workspace])
        ->expectsQuestion('Task nickname', '')
        ->expectsQuestion('Which Pest test should pass?', 'tests/GreetingTest.php')
        ->expectsQuestion('Which other files may Molly change?', '')
        ->assertSuccessful();

    $task = Task::sole();
    expect($task->nickname)->toBeNull()
        ->and($task->paths)->toBe(['tests/GreetingTest.php'])
        ->and($task->reference())->toBe($task->id);
});

it('creates a named task from flags without repeating the test path', function (array $mode): void {
    $exit = Artisan::call('molly:create', [
        'prompt' => 'Return Hello.', '--workspace' => $this->workspace,
        '--file' => ['app/Greeting.php'], '--test' => 'tests/GreetingTest.php',
        '--name' => ' Greeting ', ...$mode,
    ]);

    expect($exit)->toBe(0);
    $task = Task::sole();
    expect($task->nickname)->toBe('greeting')
        ->and($task->paths)->toBe(['app/Greeting.php', 'tests/GreetingTest.php']);
    if (isset($mode['--json'])) {
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($output['id'])->toBe($task->id)
            ->and($output['task']['nickname'])->toBe('greeting');
    }
})->with([[['--json' => true]], [['--no-interaction' => true]]]);

it('counts the included test toward the file limit', function (): void {
    config()->set('molly.max_files', 1);

    expect(fn () => app(CreateTask::class)->handle('Hello', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php'))
        ->toThrow(RuntimeException::class, 'FILES_INVALID');
    expect(Task::count())->toBe(0);
});

it('rejects an automatically included test that escapes the workspace', function (): void {
    expect(fn () => app(CreateTask::class)->handle('Hello', $this->workspace, ['app/Greeting.php'], 'tests/../../outside.php'))
        ->toThrow(RuntimeException::class, 'PATH_INVALID');
    expect(Task::count())->toBe(0);
});
