<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/*
 * A run starts child processes: each parallel check is a molly:check process in its own
 * session, and the verification check starts Pest. None of them may outlive the run, whether
 * it completed or was cancelled, and a queue worker keeps no children between jobs. Every
 * process of a fixture names the fixture's directory, so a process listing finds any that
 * were left behind.
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
 * The run ran both checks as separate processes, and the verification check ran Pest.
 *
 * @param  array<string, mixed>  $report
 */
function expectChecksRanPest(array $report): void
{
    expect($report['mode'])->toBe('parallel')
        ->and(array_column($report['branches'], 'status', 'kind'))->toBe(['verification' => 'passed', 'review' => 'passed'])
        ->and($report['verification']['tests'])->toBe(1)
        ->and($report['verification']['command'][1])->toEndWith('/vendor/bin/pest');
}

it('leaves no process behind after molly:start completes or is cancelled with Ctrl-C', function (bool $cancel): void {
    $this->root = $root = queuedExecutionFixture(testSleep: $cancel ? 30 : 1, testTimeout: 60);
    $taskId = queuedExecutionState($root)['task']['id'];
    $command = startInOwnProcessGroup([PHP_BINARY, $root.'/artisan', 'molly:start', $taskId, '--json', '--no-interaction'], $root, $root.'/storage/start');
    $this->processes = [$command['shell']];

    if ($cancel) {
        waitUntil(fn (): bool => processesMentioning(basename($root).'/vendor/bin/pest') !== [], 'Pest started', 30);
        posix_kill(-$command['pid'], SIGINT);
    }
    waitUntil(fn (): bool => ! $command['shell']->isRunning(), 'molly:start exited', 60);
    // Allow a moment for the system to reap the stopped processes. A leaked Pest process
    // from the cancelled run would still be sleeping, because its test sleeps for 30 seconds.
    usleep(500_000);
    $output = json_decode((string) file_get_contents($root.'/storage/start.out'), true);

    if ($cancel) {
        expect($output['status'])->toBe('stopped')
            ->and(array_column($output['report']['branches'], 'status', 'kind')['verification'])->toBe('cancelled');
    } else {
        expect($output['status'])->toBe('completed');
        expectChecksRanPest($output['report']);
    }
    expect(processGroupMembers($command['pid']))->toBe([])
        ->and(processesMentioning(basename($root)))->toBe([]);
})->with(['completed' => false, 'cancelled' => true]);

it('keeps no child process in the molly:worker queue worker after a job, and leaves nothing after molly:worker stop', function (): void {
    $this->root = $root = queuedExecutionFixture(testSleep: 1, testTimeout: 60);
    $start = new Process([PHP_BINARY, $root.'/artisan', 'molly:worker', 'start', '--workspace='.$root, '--json', '--no-interaction'], $root, timeout: 30);
    $start->run();
    $worker = json_decode($start->getOutput(), true);
    expect($start->getExitCode())->toBe(0, $start->getOutput().$start->getErrorOutput())
        ->and($worker['state'])->toBe('running');

    waitUntil(fn (): bool => queuedExecutionState($root)['task']['status'] !== 'running' && queuedExecutionState($root)['jobs'] === 0, 'the worker finished the job', 60);
    // Give the worker a moment to reap the processes the run stopped.
    usleep(500_000);
    $state = queuedExecutionState($root);
    $children = new Process(['pgrep', '-P', (string) $worker['pid']]);
    $children->run();

    expect($state['task']['status'])->toBe('completed');
    expectChecksRanPest($state['runs'][0]['report']);
    expect(posix_kill($worker['pid'], 0))->toBeTrue()
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
