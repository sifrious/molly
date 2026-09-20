<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\AgentBus\LocalAgentBus;
use Sifrious\Molly\AgentBus\WorkItemState;
use Sifrious\Molly\Jobs\StartSavedTask;
use Sifrious\Molly\Models\Task;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-bus-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($this->workspace);
    config(['molly.agent_bus.lease_seconds' => 60, 'molly.max_attempts' => 3]);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

function busTask(): Task
{
    return app(CreateTask::class)->handle('Return Hello.', test()->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
}

it('lets only one of two racing workers hold a valid claim', function () {
    $task = busTask();
    $bus = app(LocalAgentBus::class);

    $first = $bus->claim($task->id, 'worker-a');
    expect($first->status)->toBe('running')
        ->and($first->worker_id)->toBe('worker-a')
        ->and($first->attempt_number)->toBe(1)
        ->and($first->claimed_at)->not->toBeNull()
        ->and($first->lease_expires_at)->not->toBeNull()
        ->and(WorkItemState::fromTaskStatus($first->status))->toBe(WorkItemState::Running);

    expect(fn () => $bus->claim($task->id, 'worker-b'))
        ->toThrow(RuntimeException::class, 'TASK_NOT_PENDING');
});

it('recovers an abandoned lease so a later worker can claim', function () {
    $task = busTask();
    $bus = app(LocalAgentBus::class);
    $bus->claim($task->id, 'worker-a');
    Task::whereKey($task->id)->update([
        'lease_expires_at' => now()->subMinute(),
        'heartbeat_at' => now()->subMinutes(2),
    ]);

    $recovered = $bus->recoverAbandoned();
    expect($recovered)->toContain($task->id)
        ->and($task->fresh()->status)->toBe('failed')
        ->and($task->fresh()->worker_id)->toBeNull();

    $again = $bus->claim($task->id, 'worker-b', retry: true);
    expect($again->worker_id)->toBe('worker-b')
        ->and($again->attempt_number)->toBe(2)
        ->and($again->status)->toBe('running');
});

it('records bounded attempts independently and refuses exhausted retries', function () {
    $task = busTask();
    $bus = app(LocalAgentBus::class);
    config(['molly.max_attempts' => 2]);

    $bus->claim($task->id, 'worker-a');
    $bus->clearClaim($task->refresh());
    Task::whereKey($task->id)->update(['status' => 'failed']);
    $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => []]);

    $second = $bus->claim($task->id, 'worker-b', retry: true);
    expect($second->attempt_number)->toBe(2);
    $bus->clearClaim($second);
    Task::whereKey($task->id)->update(['status' => 'failed']);
    $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => []]);

    expect(fn () => $bus->claim($task->id, 'worker-c', retry: true))
        ->toThrow(RuntimeException::class, 'ATTEMPT_LIMIT_REACHED');
});

it('refuses duplicate delivery of a command that already succeeded', function () {
    $task = busTask();
    $bus = app(LocalAgentBus::class);
    $key = $task->id.':start';
    $bus->claim($task->id, 'worker-a', idempotencyKey: $key);
    Task::whereKey($task->id)->update(['status' => 'completed']);
    $bus->clearClaim($task->refresh());

    expect(fn () => $bus->claim($task->id, 'worker-b', idempotencyKey: $key))
        ->toThrow(RuntimeException::class, 'COMMAND_ALREADY_SUCCEEDED');
});

it('cancels via stop request without allowing a second active claim', function () {
    $task = busTask();
    $bus = app(LocalAgentBus::class);
    $bus->claim($task->id, 'worker-a');
    Task::whereKey($task->id)->update(['stop_requested_at' => now(), 'status' => 'stopped']);
    $bus->clearClaim($task->refresh());

    expect($task->fresh()->status)->toBe('stopped')
        ->and(fn () => $bus->claim($task->id, 'worker-b'))
        ->toThrow(RuntimeException::class, 'TASK_NOT_PENDING');

    $retry = $bus->claim($task->id, 'worker-b', retry: true);
    expect($retry->status)->toBe('running')->and($retry->worker_id)->toBe('worker-b');
});

it('extends a live lease on heartbeat and rejects a stale worker', function () {
    $task = busTask();
    $bus = app(LocalAgentBus::class);
    $claimed = $bus->claim($task->id, 'worker-a');
    $before = $claimed->lease_expires_at->getTimestamp();

    sleep(1);
    $beaten = $bus->heartbeat($task->id, 'worker-a');
    expect($beaten->lease_expires_at->getTimestamp())->toBeGreaterThan($before);

    expect(fn () => $bus->heartbeat($task->id, 'worker-other'))
        ->toThrow(RuntimeException::class, 'TASK_LEASE_INVALID');
});

it('uses a unique queue job id so duplicate start delivery is suppressed', function () {
    $task = busTask();
    $first = new StartSavedTask($task->id, false);
    $second = new StartSavedTask($task->id, false);
    $retry = new StartSavedTask($task->id, true);

    expect($first->uniqueId())->toBe($task->id.':start')
        ->and($second->uniqueId())->toBe($first->uniqueId())
        ->and($retry->uniqueId())->toBe($task->id.':retry');
});

it('preserves prior attempt evidence when recovering after a crash-shaped lease expiry', function () {
    $task = busTask();
    $bus = app(LocalAgentBus::class);
    $bus->claim($task->id, 'crashed-worker');
    $run = $task->runs()->create([
        'prompt' => $task->prompt,
        'workspace' => $task->workspace,
        'status' => 'failed',
        'report' => ['error' => 'Worker process interrupted.', 'verification' => ['status' => 'failed']],
    ]);
    Task::whereKey($task->id)->update([
        'lease_expires_at' => now()->subSeconds(5),
        'status' => 'running',
        'worker_id' => 'crashed-worker',
    ]);

    $bus->recoverAbandoned();
    $fresh = $task->fresh();
    expect($fresh->status)->toBe('failed')
        ->and($run->fresh()->report['error'])->toBe('Worker process interrupted.')
        ->and($fresh->attempt_number)->toBe(1);

    $reclaimed = $bus->claim($fresh->id, 'recovery-worker', retry: true);
    expect($reclaimed->attempt_number)->toBe(2)
        ->and($reclaimed->worker_id)->toBe('recovery-worker')
        ->and($run->fresh()->exists)->toBeTrue();
});
