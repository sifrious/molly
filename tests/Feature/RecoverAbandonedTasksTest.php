<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\RecoverAbandonedTasks;
use Sifrious\Molly\AgentBus\LocalAgentBus;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;
use Symfony\Component\Process\Process;

/*
 * These rows stand in for the database after a worker was killed or its host restarted:
 * tasks and runs still marked running, some with a lease that expired while the host was
 * down, some claimed on this host by a process that no longer exists.
 */

beforeEach(function (): void {
    $this->workspace = sys_get_temp_dir().'/molly-sweep-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace);
    $this->workspace = realpath($this->workspace);
});

afterEach(function (): void {
    File::deleteDirectory($this->workspace);
});

/** A pid that belonged to a process that has exited. */
function exitedPid(): int
{
    // Process::getPid() is null once the process has exited, so read it while the process runs.
    $gone = new Process(['sleep', '30']);
    $gone->start();
    $pid = $gone->getPid();
    $gone->stop(0);

    return $pid;
}

/** @return array{0: Task, 1: Run} a task and its run, both marked running by $workerId */
function abandonedTask(string $workerId, int $leaseMinutes, array $attributes = []): array
{
    $task = Task::create([
        'prompt' => 'Return Hello.', 'workspace' => test()->workspace, 'paths' => ['app/Greeting.php'], 'test_path' => 'tests/GreetingTest.php',
        'status' => 'running', 'attempt_number' => 1, 'worker_id' => $workerId,
        'claimed_at' => now()->subMinutes(20), 'heartbeat_at' => now()->subMinutes(15), 'lease_expires_at' => now()->addMinutes($leaseMinutes),
        ...$attributes,
    ]);
    $run = $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'running', 'report' => ['phase' => 'Running Pest and Tarpit review in parallel', 'changes' => [['path' => 'app/Greeting.php', 'status' => 'modified']]]]);

    return [$task, $run];
}

it('settles expired leases and claims whose process on this host exited, and leaves live claims alone', function (): void {
    $host = gethostname();
    [$expired, $expiredRun] = abandonedTask('other-host:41', -5);
    [$exited, $exitedRun] = abandonedTask($host.':'.exitedPid(), 10);
    [$stopping, $stoppingRun] = abandonedTask('other-host:42', -5, ['stop_requested_at' => now()->subMinutes(6)]);
    [$locked, $lockedRun] = abandonedTask($host.':'.getmypid(), 10);
    [$fixed, $fixedRun] = abandonedTask('fixed-worker', 10);
    [$remote, $remoteRun] = abandonedTask('other-host:43', 10);

    // A live run holds its task lock, so the sweep must leave this one running.
    $result = (new Workspace($this->workspace))->exclusivelyForTask($locked->id, fn (): array => app(RecoverAbandonedTasks::class)->handle());

    expect($result['limit_reached'])->toBeFalse()
        ->and($result['recovered'])->toEqualCanonicalizing([
            ['task_id' => $expired->id, 'reference' => $expired->id, 'status' => 'failed', 'reason' => 'lease_expired'],
            ['task_id' => $stopping->id, 'reference' => $stopping->id, 'status' => 'stopped', 'reason' => 'lease_expired'],
            ['task_id' => $exited->id, 'reference' => $exited->id, 'status' => 'failed', 'reason' => 'worker_exited'],
        ]);

    foreach ([[$expired, $expiredRun, 'failed', 'lease_expired'], [$stopping, $stoppingRun, 'stopped', 'lease_expired'], [$exited, $exitedRun, 'failed', 'worker_exited']] as [$task, $run, $status, $reason]) {
        $workerId = $task->worker_id;
        $task->refresh();
        $run->refresh();
        expect($task->only(['status', 'worker_id', 'lease_expires_at', 'heartbeat_at', 'attempt_number']))->toBe(['status' => $status, 'worker_id' => null, 'lease_expires_at' => null, 'heartbeat_at' => null, 'attempt_number' => 1])
            ->and($run->status)->toBe($status)
            ->and($run->report['error'])->toStartWith('RUN_ABANDONED: Worker '.$workerId.' ')
            ->and($run->report['recovery'])->toMatchArray(['reason' => $reason, 'worker_id' => $workerId])
            ->and($run->report['phase'])->toBe('Running Pest and Tarpit review in parallel')
            ->and($run->report['changes'])->toBe([['path' => 'app/Greeting.php', 'status' => 'modified']]);
    }
    foreach ([[$locked, $lockedRun], [$fixed, $fixedRun], [$remote, $remoteRun]] as [$task, $run]) {
        expect($task->fresh()->status)->toBe('running')
            ->and($task->fresh()->worker_id)->toBe($task->worker_id)
            ->and($run->fresh()->status)->toBe('running')
            ->and($run->fresh()->report)->not->toHaveKey('error');
    }
});

it('recovers at most the limit in one sweep, oldest lease first', function (): void {
    [$newest] = abandonedTask('other-host:1', -1);
    [$oldest] = abandonedTask('other-host:2', -30);
    [$middle] = abandonedTask('other-host:3', -10);

    $first = app(RecoverAbandonedTasks::class)->handle(limit: 2);

    expect(array_column($first['recovered'], 'task_id'))->toBe([$oldest->id, $middle->id])
        ->and($first['limit_reached'])->toBeTrue()
        ->and($newest->fresh()->status)->toBe('running');

    $second = app(RecoverAbandonedTasks::class)->handle(limit: 2);

    expect(array_column($second['recovered'], 'task_id'))->toBe([$newest->id])
        ->and($second['limit_reached'])->toBeFalse()
        ->and(app(RecoverAbandonedTasks::class)->handle()['recovered'])->toBe([]);
});

it('keeps the result of a worker that finished after the sweep read its task', function (): void {
    [$task, $run] = abandonedTask('other-host:7', -1);
    $stale = Task::findOrFail($task->id);
    Task::whereKey($task->id)->update(['status' => 'completed', 'worker_id' => null, 'lease_expires_at' => null]);
    $run->update(['status' => 'completed']);

    expect(app(LocalAgentBus::class)->recover($stale, 'lease_expired'))->toBeFalse()
        ->and($task->fresh()->status)->toBe('completed')
        ->and($run->fresh()->status)->toBe('completed')
        ->and($run->fresh()->report)->not->toHaveKey('error');
});

it('keeps a claim that a live worker renewed after the sweep read it', function (): void {
    [$task, $run] = abandonedTask('other-host:8', -1);
    $stale = Task::findOrFail($task->id);
    Task::whereKey($task->id)->update(['lease_expires_at' => now()->addMinutes(5)]);

    expect(app(LocalAgentBus::class)->recover($stale, 'lease_expired'))->toBeFalse()
        ->and($task->fresh()->status)->toBe('running')
        ->and($run->fresh()->status)->toBe('running');
});
