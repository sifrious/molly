<?php

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\ListTasks;
use Sifrious\Molly\Actions\RetryTask;
use Sifrious\Molly\Actions\ShowTask;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Actions\StopTask;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;

it('creates a saved task and returns JSON without starting a run', function (): void {
    $task = new Task;
    $task->forceFill(['id' => 'task-created', 'prompt' => 'Fix greeting', 'status' => 'pending']);
    $action = Mockery::mock(CreateTask::class);
    $action->shouldReceive('handle')->once()->with('Fix greeting', '/tmp/project', ['app/Greeting.php'], 'tests/Feature/GreetingTest.php')->andReturn($task);
    $this->app->instance(CreateTask::class, $action);
    $start = Mockery::mock(StartTask::class);
    $start->shouldNotReceive('handle');
    $this->app->instance(StartTask::class, $start);

    $exit = Artisan::call('molly:create', ['prompt' => ' Fix greeting ', '--workspace' => '/tmp/project', '--file' => ['app/Greeting.php'], '--test' => 'tests/Feature/GreetingTest.php', '--json' => true]);

    expect($exit)->toBe(0)->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'id' => 'task-created', 'status' => 'pending', 'task' => ['status' => 'pending', 'id' => 'task-created', 'prompt' => 'Fix greeting'],
    ]);
});

it('requires a prompt when creating a task without interaction', function (array $options): void {
    $action = Mockery::mock(CreateTask::class);
    $action->shouldNotReceive('handle');
    $this->app->instance(CreateTask::class, $action);

    $this->artisan('molly:create', $options)
        ->expectsOutputToContain('Provide a prompt when using --json or --no-interaction.')
        ->assertFailed();
})->with([[['--json' => true]], [['--no-interaction' => true]]]);

it('asks for a prompt before saving an interactive task', function (): void {
    $task = new Task;
    $task->forceFill(['id' => 'task-prompted', 'prompt' => 'Fix greeting', 'workspace' => '/tmp/project', 'paths' => ['app/Greeting.php'], 'test_path' => 'tests/Feature/GreetingTest.php', 'status' => 'pending']);
    $action = Mockery::mock(CreateTask::class);
    $action->shouldReceive('handle')->once()->with('Fix greeting', Mockery::type('string'), ['app/Greeting.php'], 'tests/Feature/GreetingTest.php')->andReturn($task);
    $this->app->instance(CreateTask::class, $action);

    $this->artisan('molly:create', ['--file' => ['app/Greeting.php'], '--test' => 'tests/Feature/GreetingTest.php'])
        ->expectsQuestion('What should Molly work on?', 'Fix greeting')
        ->expectsQuestion('Task nickname', '')
        ->expectsOutputToContain('php artisan molly:start task-prompted')
        ->assertSuccessful();
});

it('lists task JSON using the requested limit', function (): void {
    $task = new Task;
    $task->forceFill(['id' => 'listed-task', 'status' => 'failed', 'prompt' => 'Fix greeting']);
    $action = Mockery::mock(ListTasks::class);
    $action->shouldReceive('handle')->once()->with(3)->andReturn(new Collection([$task]));
    $this->app->instance(ListTasks::class, $action);

    $exit = Artisan::call('molly:tasks', ['--limit' => '3', '--json' => true]);

    expect($exit)->toBe(0)->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'tasks' => [['status' => 'failed', 'id' => 'listed-task', 'prompt' => 'Fix greeting']],
    ]);
});

it('rejects an invalid task list limit', function (string $limit): void {
    $action = Mockery::mock(ListTasks::class);
    $action->shouldNotReceive('handle');
    $this->app->instance(ListTasks::class, $action);

    $exit = Artisan::call('molly:tasks', ['--limit' => $limit, '--json' => true]);

    expect($exit)->toBe(1)->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'tasks' => [], 'error' => 'Use --limit with a positive whole number.',
    ]);
})->with(['0', '-1', '1.5', 'many']);

it('explains how to create the first task', function (): void {
    $action = Mockery::mock(ListTasks::class);
    $action->shouldReceive('handle')->once()->with(20)->andReturn(new Collection);
    $this->app->instance(ListTasks::class, $action);

    $this->artisan('molly:tasks')->expectsOutputToContain('No tasks yet. Create a task with php artisan molly:create.')->assertSuccessful();
});

it('reads task history without starting another attempt', function (): void {
    $task = new Task;
    $task->forceFill(['id' => 'saved-task', 'status' => 'failed']);
    $run = new Run;
    $run->forceFill(['id' => 'saved-run', 'status' => 'failed']);
    $task->setRelation('runs', collect([$run]));
    $action = Mockery::mock(ShowTask::class);
    $action->shouldReceive('handle')->once()->with('saved-task')->andReturn($task);
    $this->app->instance(ShowTask::class, $action);

    $exit = Artisan::call('molly:task', ['task' => 'saved-task', '--json' => true]);

    expect($exit)->toBe(0)->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'id' => 'saved-task', 'status' => 'failed', 'task' => ['status' => 'failed', 'id' => 'saved-task', 'runs' => [['id' => 'saved-run', 'status' => 'failed']]],
    ]);
});

it('returns an error for an unknown task', function (): void {
    $action = Mockery::mock(ShowTask::class);
    $action->shouldReceive('handle')->once()->with('unknown')->andReturnNull();
    $this->app->instance(ShowTask::class, $action);

    $exit = Artisan::call('molly:task', ['task' => 'unknown', '--json' => true]);

    expect($exit)->toBe(1)->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'id' => 'unknown', 'status' => 'error', 'error' => 'TASK_NOT_FOUND: No saved task has that ID.',
    ]);
});

it('returns run evidence and exit status for a saved task attempt', function (string $command, string $actionClass, string $status, int $expectedExit): void {
    $run = new Run;
    $run->forceFill(['id' => 'attempt-id', 'task_id' => 'task-id', 'status' => $status, 'report' => ['summary' => 'Review the changes.']]);
    $action = Mockery::mock($actionClass);
    $action->shouldReceive('handle')->once()->with('task-id', null)->andReturn($run);
    $this->app->instance($actionClass, $action);

    $exit = Artisan::call($command, ['task' => 'task-id', '--json' => true]);

    expect($exit)->toBe($expectedExit)->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'id' => 'attempt-id', 'task_id' => 'task-id', 'status' => $status, 'report' => ['summary' => 'Review the changes.'],
    ]);
})->with([
    ['molly:start', StartTask::class, 'completed', 0],
    ['molly:start', StartTask::class, 'failed', 1],
    ['molly:retry', RetryTask::class, 'completed', 0],
    ['molly:retry', RetryTask::class, 'stopped', 1],
]);

it('returns execution errors as JSON', function (string $command, string $actionClass): void {
    $action = Mockery::mock($actionClass);
    $action->shouldReceive('handle')->once()->andThrow(new RuntimeException('TASK_NOT_FOUND: No saved task has that ID.'));
    $this->app->instance($actionClass, $action);

    $exit = Artisan::call($command, ['task' => 'unknown', '--json' => true]);

    expect($exit)->toBe(1)->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'id' => null, 'task_id' => 'unknown', 'status' => 'error', 'report' => ['error' => 'TASK_NOT_FOUND: No saved task has that ID.'],
    ]);
})->with([['molly:start', StartTask::class], ['molly:retry', RetryTask::class]]);

it('explains that a running task stops at an execution boundary', function (): void {
    $task = new Task;
    $task->forceFill(['id' => 'task-running', 'prompt' => 'Fix greeting', 'workspace' => '/tmp/project', 'paths' => ['app/Greeting.php'], 'test_path' => 'tests/Feature/GreetingTest.php', 'status' => 'running', 'stop_requested_at' => now()]);
    $action = Mockery::mock(StopTask::class);
    $action->shouldReceive('handle')->once()->with('task-running')->andReturn($task);
    $this->app->instance(StopTask::class, $action);

    $this->artisan('molly:stop', ['task' => 'task-running'])
        ->expectsOutputToContain('running')
        ->expectsOutputToContain('An active model request or test process may finish first.')
        ->assertSuccessful();
});

it('prints action errors without extra JSON output', function (string $command, string $actionClass, array $arguments, array $expected): void {
    $action = Mockery::mock($actionClass);
    $action->shouldReceive('handle')->once()->andThrow(new RuntimeException('The selected task cannot change state.'));
    $this->app->instance($actionClass, $action);

    $exit = Artisan::call($command, [...$arguments, '--json' => true]);

    expect($exit)->toBe(1)->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe($expected);
})->with([
    ['molly:create', CreateTask::class, ['prompt' => 'Fix greeting', '--test' => 'tests/GreetingTest.php'], ['id' => null, 'status' => 'error', 'error' => 'The selected task cannot change state.']],
    ['molly:stop', StopTask::class, ['task' => 'task-id'], ['id' => 'task-id', 'status' => 'error', 'error' => 'The selected task cannot change state.']],
    ['molly:tasks', ListTasks::class, [], ['tasks' => [], 'error' => 'The selected task cannot change state.']],
]);

it('renders progress and run evidence when starting or retrying a task', function (string $command, string $actionClass): void {
    $run = new Run;
    $run->forceFill(['id' => 'attempt-id', 'task_id' => 'task-id', 'workspace' => '/tmp/project', 'status' => 'failed', 'report' => ['error' => 'Required tests failed.']]);
    $action = Mockery::mock($actionClass);
    $action->shouldReceive('handle')->once()->with('task-id', Mockery::type(Closure::class))->andReturnUsing(function (string $id, Closure $progress) use ($run): Run {
        $progress('Running required tests.');

        return $run;
    });
    $this->app->instance($actionClass, $action);

    $this->artisan($command, ['task' => 'task-id'])
        ->expectsOutputToContain('Running required tests.')
        ->expectsOutputToContain('Required tests failed.')
        ->expectsOutputToContain('Run attempt-id / failed')
        ->assertFailed();
})->with([['molly:start', StartTask::class], ['molly:retry', RetryTask::class]]);

it('returns the stopped task as JSON', function (): void {
    $task = new Task;
    $task->forceFill(['id' => 'task-id', 'status' => 'stopped']);
    $action = Mockery::mock(StopTask::class);
    $action->shouldReceive('handle')->once()->with('task-id')->andReturn($task);
    $this->app->instance(StopTask::class, $action);

    $exit = Artisan::call('molly:stop', ['task' => 'task-id', '--json' => true]);

    expect($exit)->toBe(0)->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'id' => 'task-id', 'status' => 'stopped', 'task' => ['status' => 'stopped', 'id' => 'task-id'],
    ]);
});
