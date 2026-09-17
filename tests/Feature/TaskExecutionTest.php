<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RetryTask;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Actions\StopTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-execution-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

function savedExecutionTask(): Task
{
    return app(CreateTask::class)->handle('Return Hello.', test()->workspace, ['app/Greeting.php', 'tests/GreetingTest.php'], 'tests/GreetingTest.php');
}

function prepareTaskExecution(string $verification = 'passed'): void
{
    test()->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'ok', 'probes' => []]);
    test()->mock(GenerateChanges::class)->shouldReceive('handle')->once()->andReturn([
        'summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']],
    ]);
    test()->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => $verification, 'tests' => 1, 'assertions' => 1]);
    test()->mock(ReviewChanges::class)->shouldReceive('handle')->once()->andReturn([
        'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']), 'findings' => [],
    ]);
}

it('links a completed run to the task and rejects a duplicate start during execution', function () {
    $task = savedExecutionTask();
    prepareTaskExecution();
    $checked = false;

    $run = app(StartTask::class)->handle($task->id, function () use ($task, &$checked) {
        if (! $checked) {
            $checked = true;
            expect(fn () => app(StartTask::class)->handle($task->id))->toThrow(RuntimeException::class, 'WORKSPACE_BUSY');
        }
    });

    expect($checked)->toBeTrue()
        ->and($task->fresh()->status)->toBe('completed')
        ->and($task->runs()->sole()->id)->toBe($run->id)
        ->and($run->task_id)->toBe($task->id)
        ->and($run->report['verification']['status'])->toBe('passed');
});

it('keeps failed run evidence when a later retry passes', function () {
    $task = savedExecutionTask();
    $task->update(['status' => 'failed']);
    $old = Run::create(['task_id' => $task->id, 'prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => ['error' => 'First attempt failed.']]);
    prepareTaskExecution();

    $run = app(RetryTask::class)->handle($task->id);

    expect($run->status)->toBe('completed')
        ->and($task->runs()->count())->toBe(2)
        ->and($old->fresh()->report)->toBe(['error' => 'First attempt failed.'])
        ->and($task->fresh()->status)->toBe('completed');
});

it('limits retries without starting another model call', function () {
    $task = savedExecutionTask();
    $task->update(['status' => 'failed']);
    Run::create(['task_id' => $task->id, 'prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => []]);
    config(['molly.max_attempts' => 1]);
    $this->mock(GenerateChanges::class)->shouldNotReceive('handle');

    expect(fn () => app(RetryTask::class)->handle($task->id))->toThrow(RuntimeException::class, 'ATTEMPT_LIMIT_REACHED');
    expect($task->fresh()->status)->toBe('failed')->and($task->runs()->count())->toBe(1);
});

it('stops after generation without applying the proposal', function () {
    $task = savedExecutionTask();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->once()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(GenerateChanges::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($task) {
        expect(app(StopTask::class)->handle($task->id)->status)->toBe('running');

        return ['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]];
    });
    $this->mock(VerifyChanges::class)->shouldNotReceive('handle');

    $run = app(StartTask::class)->handle($task->id);

    expect($run->status)->toBe('stopped')
        ->and($run->report['error'])->toStartWith('RUN_STOPPED:')
        ->and($task->fresh()->status)->toBe('stopped')
        ->and(File::get($task->workspace.'/app/Greeting.php'))->toBe('<?php return null;');
});

it('keeps applied edits and test evidence when stopped during verification', function () {
    $task = savedExecutionTask();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->once()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(GenerateChanges::class)->shouldReceive('handle')->once()->andReturn(['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($task) {
        app(StopTask::class)->handle($task->id);

        return ['status' => 'passed', 'tests' => 1];
    });
    $this->mock(ReviewChanges::class)->shouldNotReceive('handle');

    $run = app(StartTask::class)->handle($task->id);

    expect($run->status)->toBe('stopped')
        ->and($run->report['verification']['tests'])->toBe(1)
        ->and(File::get($task->workspace.'/app/Greeting.php'))->toBe('<?php return "Hello";');
});

it('retries stopped tasks with a new attempt and clears the stop request', function () {
    $task = savedExecutionTask();
    app(StopTask::class)->handle($task->id);
    prepareTaskExecution();

    $run = app(RetryTask::class)->handle($task->id);

    expect($run->status)->toBe('completed')->and($task->fresh()->stop_requested_at)->toBeNull();
});

it('leaves the task pending when workspace validation fails before claiming execution', function () {
    $task = savedExecutionTask();
    File::deleteDirectory($task->workspace);

    expect(fn () => app(StartTask::class)->handle($task->id))->toThrow(RuntimeException::class, 'WORKSPACE_INVALID');
    expect($task->fresh()->status)->toBe('pending')->and($task->runs()->count())->toBe(0);
});

it('keeps verification failure authoritative for task status', function () {
    $task = savedExecutionTask();
    prepareTaskExecution('failed');

    $run = app(StartTask::class)->handle($task->id);

    expect($run->status)->toBe('failed')->and($task->fresh()->status)->toBe('failed');
});

it('rejects invalid attempt limits without leaving a task running', function ($limit) {
    $task = savedExecutionTask();
    config(['molly.max_attempts' => $limit]);

    expect(fn () => app(StartTask::class)->handle($task->id))->toThrow(RuntimeException::class, 'ATTEMPT_LIMIT_INVALID');
    expect($task->fresh()->status)->toBe('pending');
})->with([0, 11, '3']);

it('does not retry pending or completed tasks', function (string $status) {
    $task = savedExecutionTask();
    $task->update(['status' => $status]);

    expect(fn () => app(RetryTask::class)->handle($task->id))->toThrow(RuntimeException::class, 'TASK_NOT_RETRYABLE');
    expect($task->fresh()->status)->toBe($status);
})->with(['pending', 'running', 'completed']);

it('classifies an unknown task before starting or retrying', function () {
    expect(fn () => app(StartTask::class)->handle('missing'))->toThrow(RuntimeException::class, 'TASK_NOT_FOUND');
    expect(fn () => app(RetryTask::class)->handle('missing'))->toThrow(RuntimeException::class, 'TASK_NOT_FOUND');
});
