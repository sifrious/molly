<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * A real queue worker is killed with SIGKILL while the model is answering. Nothing in that
 * process can run cleanup, so the task and its run stay marked running. These tests prove
 * the records are settled afterwards and that the killed job never runs the task again.
 */

beforeEach(function (): void {
    $this->processes = [];
});

afterEach(function (): void {
    foreach ($this->processes as $process) {
        $process->stop(0);
    }
    if (isset($this->root)) {
        foreach (processesMentioning($this->root) as $line) {
            @posix_kill((int) $line, SIGKILL);
        }
        File::deleteDirectory($this->root);
    }
});

/** Start one queue:work --once process in the fixture and remember it for cleanup. */
function crashFixtureWorker(string $root): Process
{
    $worker = new Process([PHP_BINARY, $root.'/artisan', 'queue:work', 'database', '--once', '--tries=1', '--sleep=0', '--no-interaction'], $root, timeout: 60);
    $worker->start();
    test()->processes = [...test()->processes, $worker];

    return $worker;
}

/** Kill the worker with SIGKILL while the fake model call is waiting, and wait for it to exit. */
function killWorkerDuringGeneration(string $root): int
{
    touch($root.'/hold');
    $worker = crashFixtureWorker($root);
    waitUntil(fn (): bool => is_file($root.'/generation-calls'), 'the worker reached the model call');
    $pid = $worker->getPid();
    posix_kill($pid, SIGKILL);
    waitUntil(fn (): bool => ! $worker->isRunning(), 'the killed worker exited', 5);
    unlink($root.'/hold');

    expect($worker->getTermSignal())->toBe(SIGKILL);

    return $pid;
}

it('recovers a task whose worker was killed with SIGKILL and runs it again only when retried', function (): void {
    $this->root = $root = queuedExecutionFixture(retryAfter: 1);
    $pid = killWorkerDuringGeneration($root);

    $crashed = queuedExecutionState($root);
    expect($crashed['task']['status'])->toBe('running')
        ->and($crashed['task']['worker_id'])->toBe(gethostname().':'.$pid)
        ->and(strtotime($crashed['task']['lease_expires_at']))->toBeGreaterThan(time())
        ->and($crashed['runs'])->toHaveCount(1)
        ->and($crashed['runs'][0]['status'])->toBe('running')
        ->and(processesMentioning($root))->toBe([]);

    // The database queue hands the killed job to the next worker after retry_after. It must not run the task again.
    sleep(2);
    $redelivered = crashFixtureWorker($root);
    $redelivered->wait();
    $redeliveredState = queuedExecutionState($root);
    expect(file($root.'/generation-calls', FILE_IGNORE_NEW_LINES))->toHaveCount(1)
        ->and($redeliveredState['failed'])->toHaveCount(1)
        ->and($redeliveredState['failed'][0]['exception'])->toContain('MaxAttemptsExceededException')
        ->and($redeliveredState['jobs'])->toBe(0)
        ->and($redeliveredState['runs'])->toHaveCount(1);

    // The next retry holds the task lock, sees the claim came from this host, and settles it before claiming.
    $retry = new Process([PHP_BINARY, $root.'/artisan', 'molly:retry', $crashed['task']['id'], '--json', '--no-interaction'], $root, timeout: 60);
    $retry->run();
    $result = json_decode($retry->getOutput(), true);
    $state = queuedExecutionState($root);
    $lifecycle = array_map(fn (string $line): array => json_decode($line, true), file($root.'/.molly/lifecycle.jsonl', FILE_IGNORE_NEW_LINES));
    $recovery = array_values(array_filter($lifecycle, fn (array $event): bool => ($event['payload']['reason'] ?? null) === 'worker_exited'));

    expect($retry->getExitCode())->toBe(0, $retry->getOutput().$retry->getErrorOutput())
        ->and($result['status'])->toBe('completed')
        ->and($state['task']['status'])->toBe('completed')
        ->and((int) $state['task']['attempt_number'])->toBe(2)
        ->and($state['runs'])->toHaveCount(2)
        ->and($state['runs'][0]['status'])->toBe('failed')
        ->and($state['runs'][0]['report']['error'])->toStartWith('RUN_ABANDONED: Worker '.gethostname().':'.$pid.' exited before this run finished')
        ->and($state['runs'][0]['report']['recovery']['reason'])->toBe('worker_exited')
        ->and($state['runs'][1]['status'])->toBe('completed')
        ->and(file($root.'/generation-calls', FILE_IGNORE_NEW_LINES))->toHaveCount(2)
        ->and(file_get_contents($root.'/app/Flag.php'))->toBe("<?php\nreturn true;\n")
        ->and($recovery)->toHaveCount(1)
        ->and($recovery[0]['type'])->toBe('failed')
        ->and($recovery[0]['payload']['runs'])->toBe([$state['runs'][0]['id']])
        ->and(processesMentioning($root))->toBe([]);
});

it('refuses a plain start of a crashed task and leaves it failed and retryable', function (): void {
    $this->root = $root = queuedExecutionFixture();
    killWorkerDuringGeneration($root);
    $taskId = queuedExecutionState($root)['task']['id'];

    $start = new Process([PHP_BINARY, $root.'/artisan', 'molly:start', $taskId, '--json', '--no-interaction'], $root, timeout: 60);
    $start->run();
    $state = queuedExecutionState($root);

    expect($start->getExitCode())->toBe(1)
        ->and(json_decode($start->getOutput(), true)['report']['error'])->toStartWith('TASK_NOT_PENDING:')
        ->and($state['task']['status'])->toBe('failed')
        ->and($state['task']['worker_id'])->toBeNull()
        ->and((int) $state['task']['attempt_number'])->toBe(1)
        ->and($state['runs'])->toHaveCount(1)
        ->and($state['runs'][0]['status'])->toBe('failed')
        ->and(file($root.'/generation-calls', FILE_IGNORE_NEW_LINES))->toHaveCount(1)
        ->and(file_get_contents($root.'/app/Flag.php'))->toBe("<?php\nreturn false;\n");
});
