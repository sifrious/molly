<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * A run starts child processes: each parallel check is a molly:check process in its own
 * session, and each check starts Pest. None of them may outlive the run, whether it completed
 * or was cancelled, and a queue worker keeps no children between jobs. Every process of a
 * fixture names the fixture's directory, so a process listing finds any that were left behind.
 */

beforeEach(function (): void {
    $this->processes = [];
});

afterEach(function (): void {
    foreach ($this->processes as $process) {
        $process->stop(0);
    }
    if (isset($this->root)) {
        foreach (processesMentioning(basename($this->root)) as $line) {
            @posix_kill((int) $line, SIGKILL);
        }
        File::deleteDirectory($this->root);
    }
});

/**
 * Wait for $done while recording which kinds of child process of the fixture were seen.
 *
 * @return array{checks: bool, pest: bool}
 */
function watchChildren(string $root, Closure $done, float $seconds = 60): array
{
    $seen = ['checks' => false, 'pest' => false];
    waitUntil(function () use ($root, $done, &$seen): bool {
        foreach (processesMentioning(basename($root)) as $line) {
            $seen['checks'] = $seen['checks'] || str_contains($line, 'molly:check');
            $seen['pest'] = $seen['pest'] || str_contains($line, '/vendor/bin/pest');
        }

        return $done();
    }, 'the run finished', $seconds);

    return $seen;
}

it('leaves no process behind after molly:start completes or is cancelled with Ctrl-C', function (bool $cancel): void {
    $this->root = $root = queuedExecutionFixture(testSleep: $cancel ? 30 : 2, testTimeout: 60);
    $taskId = queuedExecutionState($root)['task']['id'];
    $command = startInOwnProcessGroup([PHP_BINARY, $root.'/artisan', 'molly:start', $taskId, '--json', '--no-interaction'], $root, $root.'/storage/start');
    $this->processes = [$command['shell']];

    // A cancelled run is signalled once its Pest check is running; a completed run is watched to the end.
    $seen = watchChildren($root, fn (): bool => $cancel ? processesMentioning(basename($root).'/vendor/bin/pest') !== [] : ! $command['shell']->isRunning());
    if ($cancel) {
        posix_kill(-$command['pid'], SIGINT);
        waitUntil(fn (): bool => ! $command['shell']->isRunning(), 'molly:start exited');
    }

    // Allow a moment for the system to reap the killed processes. A leaked Pest process
    // would still be sleeping here, because the required test sleeps for 30 seconds.
    usleep(500_000);
    expect(json_decode((string) file_get_contents($root.'/storage/start.out'), true)['status'])->toBe($cancel ? 'stopped' : 'completed')
        ->and($seen)->toBe(['checks' => true, 'pest' => true])
        ->and(processGroupMembers($command['pid']))->toBe([])
        ->and(processesMentioning(basename($root)))->toBe([]);
})->with(['completed' => false, 'cancelled' => true]);

it('keeps no child process in the molly:worker queue worker after a job, and leaves nothing after molly:worker stop', function (): void {
    $this->root = $root = queuedExecutionFixture(testSleep: 2, testTimeout: 60);
    $start = new Process([PHP_BINARY, $root.'/artisan', 'molly:worker', 'start', '--workspace='.$root, '--json', '--no-interaction'], $root, timeout: 30);
    $start->run();
    $worker = json_decode($start->getOutput(), true);
    expect($start->getExitCode())->toBe(0, $start->getOutput().$start->getErrorOutput())
        ->and($worker['state'])->toBe('running');

    $seen = watchChildren($root, fn (): bool => queuedExecutionState($root)['task']['status'] !== 'running' && queuedExecutionState($root)['jobs'] === 0);
    // The job has finished; give the worker a moment to reap the processes the run stopped.
    usleep(500_000);
    $children = new Process(['pgrep', '-P', (string) $worker['pid']]);
    $children->run();

    expect(queuedExecutionState($root)['task']['status'])->toBe('completed')
        ->and($seen)->toBe(['checks' => true, 'pest' => true])
        ->and(posix_kill($worker['pid'], 0))->toBeTrue()
        ->and(trim($children->getOutput()))->toBe('')
        ->and(processGroupMembers($worker['pgid']))->toBe([$worker['pid']])
        ->and(array_map(intval(...), processesMentioning(basename($root))))->toBe([$worker['pid']]);

    $stop = new Process([PHP_BINARY, $root.'/artisan', 'molly:worker', 'stop', '--workspace='.$root, '--timeout=10', '--json', '--no-interaction'], $root, timeout: 30);
    $stop->run();
    usleep(500_000);

    expect($stop->getExitCode())->toBe(0, $stop->getOutput().$stop->getErrorOutput())
        ->and(json_decode($stop->getOutput(), true)['signal'])->toBe('SIGTERM')
        ->and(processGroupMembers($worker['pgid']))->toBe([])
        ->and(processesMentioning(basename($root)))->toBe([]);
});
