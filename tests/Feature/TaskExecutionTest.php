<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RetryTask;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Actions\StopTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-execution-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($this->workspace);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

function savedExecutionTask(): Task
{
    return app(CreateTask::class)->handle('Return Hello.', test()->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
}

function prepareTaskExecution(string $verification = 'passed'): void
{
    test()->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'ok', 'probes' => []]);
    test()->mock(GenerateChanges::class)->shouldReceive('handle')->once()->andReturn([
        'summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']],
    ]);
    test()->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => $verification, 'tests' => 1, 'assertions' => 1, 'identified_required_test' => $verification === 'passed']);
    test()->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle')->once()->andReturn([
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

it('sends bounded diagnostics from the latest attempt while preserving task scope and history', function (): void {
    Http::preventStrayRequests();
    $task = savedExecutionTask();
    $task->update(['status' => 'failed']);
    Run::create(['task_id' => $task->id, 'prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => ['error' => 'Obsolete failure.'], 'created_at' => now()->subMinute()]);
    $finding = ['code' => 'E', 'classification' => 'accidental', 'severity' => 'blocking', 'path' => 'app/Greeting.php', 'line' => 1, 'problem' => str_repeat('Needless wrapper. ', 100), 'recommendation' => 'Call the existing function.', 'source' => 'PRIVATE_SOURCE'];
    $report = [
        'verification' => ['status' => 'failed', 'tests' => 1, 'errors' => 1, 'output' => 'Call to undefined function get().'.str_repeat("\n\"😀", 3000), 'reason' => 'tests_failed', 'environment' => ['token' => 'SECRET_TOKEN']],
        'review' => ['findings' => [['path' => 'outside.php', 'problem' => 'OUTSIDE_SCOPE'], ...array_fill(0, 3, [...$finding, 'severity' => 'warning', 'problem' => 'NONBLOCKING_FINDING']), ...array_fill(0, 5, $finding)]],
        'error' => str_repeat('Prior attempt failed. ', 100),
        'source' => 'PRIVATE_SOURCE', 'config' => ['token' => 'SECRET_TOKEN'],
    ];
    $old = Run::create(['task_id' => $task->id, 'prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => $report]);
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true]);
    $this->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle')->once()->andReturn([
        'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']), 'findings' => [],
    ]);
    ChangeWriter::fake([['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]]])->preventStrayPrompts();

    $run = app(RetryTask::class)->handle($task->id);

    expect($run->status)->toBe('completed')
        ->and($old->fresh()->report)->toBe($report)
        ->and($task->fresh()->prompt)->toBe('Return Hello.');
    ChangeWriter::assertPrompted(function (AgentPrompt $prompt) use ($old): bool {
        $payload = json_decode($prompt->prompt, true, flags: JSON_THROW_ON_ERROR);
        $evidence = $payload['previous_attempt'];
        $encoded = json_encode($evidence, JSON_THROW_ON_ERROR);
        expect($payload['task'])->toBe('Return Hello.')
            ->and(array_keys($payload['allowed_files']))->toBe(['app/Greeting.php'])
            ->and($payload['required_test'])->toBe('tests/GreetingTest.php')
            ->and($evidence['run_id'])->toBe($old->id)
            ->and($evidence['verification'])->toMatchArray(['status' => 'failed', 'tests' => 1, 'errors' => 1, 'reason' => 'tests_failed'])
            ->and($evidence['verification']['output'])->toStartWith('Call to undefined function get().')
            ->and($evidence['review_findings'])->toHaveCount(3)
            ->and($evidence['review_findings'][0]['recommendation'])->toBe('Call the existing function.')
            ->and(strlen($encoded))->toBeLessThanOrEqual(8192)
            ->and($encoded)->not->toContain('Obsolete failure.', 'SECRET_TOKEN', 'PRIVATE_SOURCE', 'OUTSIDE_SCOPE', 'NONBLOCKING_FINDING');

        return true;
    });
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
    $this->mock(ReviewChanges::class)->makePartial()->shouldNotReceive('handle');

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
