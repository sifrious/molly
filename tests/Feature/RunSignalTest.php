<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\RunStopped;
use Symfony\Component\Process\Process;

/*
 * Ctrl-C and SIGTERM while molly:start or molly:retry runs a task. The subprocess tests run the
 * real commands against the queued-runtime fixture, with fake model and review agents and real
 * Pest checks, and signal them the way a terminal or a process manager does.
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
    if (isset($this->workspace)) {
        File::deleteDirectory($this->workspace);
    }
});

/** @return list<array<string, mixed>> the lifecycle events recorded in the fixture */
function signalLifecycle(string $root): array
{
    return array_map(fn (string $line): array => json_decode($line, true), file($root.'/.molly/lifecycle.jsonl', FILE_IGNORE_NEW_LINES));
}

it('stops the run, cancels its Pest check, and exits 130 when Ctrl-C reaches molly:start', function (): void {
    $this->root = $root = queuedExecutionFixture(testSleep: 30, testTimeout: 60);
    $taskId = queuedExecutionState($root)['task']['id'];
    $command = startInOwnProcessGroup([PHP_BINARY, $root.'/artisan', 'molly:start', $taskId, '--json', '--no-interaction'], $root, $root.'/storage/start');
    $this->processes = [$command['shell']];

    // The required test sleeps for 30 seconds and may run for 60, so Pest is still running when the
    // signal arrives, and only the signal can end the run sooner.
    waitUntil(fn (): bool => processesMentioning(basename($root).'/vendor/bin/pest') !== [], 'Pest started', 30);
    $signalled = microtime(true);
    posix_kill(-$command['pid'], SIGINT);
    $command['shell']->wait();
    $elapsed = microtime(true) - $signalled;
    waitUntil(fn (): bool => processesMentioning(basename($root)) === [], 'every process of the run exited', 10);

    $output = json_decode((string) file_get_contents($root.'/storage/start.out'), true);
    $state = queuedExecutionState($root);
    $branches = array_column($state['runs'][0]['report']['branches'], 'status', 'kind');
    $stopped = array_values(array_filter(signalLifecycle($root), fn (array $event): bool => $event['type'] === 'stopped'));

    expect(trim((string) file_get_contents($root.'/storage/start.exit')))->toBe('130')
        ->and($elapsed)->toBeLessThan(20)
        ->and($output['status'])->toBe('stopped', (string) file_get_contents($root.'/storage/start.err'))
        ->and($output['report']['error'])->toStartWith('RUN_STOPPED:')
        ->and($output['report']['stop_reason'])->toBe('Molly received SIGINT and stopped at the next step.')
        ->and($state['task']['status'])->toBe('stopped')
        ->and($state['task']['stop_requested_at'])->not->toBeNull()
        ->and($state['task']['worker_id'])->toBeNull()
        ->and($state['runs'])->toHaveCount(1)
        ->and($state['runs'][0]['status'])->toBe('stopped')
        ->and($branches['verification'])->toBe('cancelled')
        ->and($stopped)->toHaveCount(1)
        ->and($stopped[0]['run_id'])->toBe($state['runs'][0]['id'])
        // The proposal was applied before the checks started; a stopped run keeps it for review.
        ->and(file_get_contents($root.'/app/Flag.php'))->toBe("<?php\nreturn true;\n")
        ->and(processGroupMembers($command['pid']))->toBe([]);
});

it('stops before applying changes on SIGTERM during the model call, and molly:retry stops the same way on SIGINT', function (): void {
    $this->root = $root = queuedExecutionFixture();
    $taskId = queuedExecutionState($root)['task']['id'];

    $interrupt = function (string $command, int $signal, int $generation) use ($root, $taskId): Process {
        touch($root.'/hold');
        $process = new Process([PHP_BINARY, $root.'/artisan', $command, $taskId, '--json', '--no-interaction'], $root, timeout: 60);
        $process->start();
        $this->processes = [...$this->processes, $process];
        waitUntil(fn (): bool => is_file($root.'/generation-calls') && count(file($root.'/generation-calls')) === $generation, $command.' reached the model call');
        posix_kill($process->getPid(), $signal);
        waitUntil(fn (): bool => queuedExecutionState($root)['task']['stop_requested_at'] !== null, $command.' recorded the stop request');
        // The model answers only now, after the signal, the way a slow request finishes.
        unlink($root.'/hold');
        $process->wait();

        return $process;
    };

    $start = $interrupt('molly:start', SIGTERM, 1);
    $afterStart = queuedExecutionState($root);

    expect($start->hasBeenSignaled())->toBeFalse()
        ->and($start->getExitCode())->toBe(143, $start->getErrorOutput())
        ->and(json_decode($start->getOutput(), true)['status'])->toBe('stopped')
        ->and($afterStart['task']['status'])->toBe('stopped')
        ->and($afterStart['runs'][0]['status'])->toBe('stopped')
        ->and($afterStart['runs'][0]['report']['stop_reason'])->toBe('Molly received SIGTERM and stopped at the next step.')
        ->and($afterStart['runs'][0]['report']['changes'])->toBe([])
        ->and(file_get_contents($root.'/app/Flag.php'))->toBe("<?php\nreturn false;\n");

    $retry = $interrupt('molly:retry', SIGINT, 2);
    $afterRetry = queuedExecutionState($root);

    expect($retry->hasBeenSignaled())->toBeFalse()
        ->and($retry->getExitCode())->toBe(130, $retry->getErrorOutput())
        ->and($afterRetry['task']['status'])->toBe('stopped')
        ->and((int) $afterRetry['task']['attempt_number'])->toBe(2)
        ->and(array_column($afterRetry['runs'], 'status'))->toBe(['stopped', 'stopped'])
        ->and($afterRetry['runs'][1]['report']['stop_reason'])->toBe('Molly received SIGINT and stopped at the next step.')
        ->and(file_get_contents($root.'/app/Flag.php'))->toBe("<?php\nreturn false;\n")
        ->and(processesMentioning(basename($root)))->toBe([]);
});

describe('in process', function (): void {
    beforeEach(function (): void {
        $this->workspace = sys_get_temp_dir().'/molly-signal-'.Str::uuid();
        File::ensureDirectoryExists($this->workspace.'/app');
        File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
        writeProtectedTest($this->workspace);
        commitGitWorkspace($this->workspace);
        $this->task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    });

    it('leaves the task unclaimed when the signal arrives before the attempt begins', function (): void {
        $start = app(StartTask::class);
        $start->interrupt(SIGINT);

        expect(fn () => $start->handle($this->task->id))->toThrow(RunStopped::class, 'RUN_STOPPED: Molly received SIGINT before the attempt began. The task was not claimed.')
            ->and($this->task->fresh()->only(['status', 'attempt_number', 'worker_id', 'stop_requested_at']))->toBe(['status' => 'pending', 'attempt_number' => 0, 'worker_id' => null, 'stop_requested_at' => null])
            ->and($this->task->runs()->count())->toBe(0);
    });

    it('records the stop request and stops at the next step when a signal arrives during the run', function (): void {
        $start = null;
        $this->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'ok', 'probes' => []]);
        $this->mock(GenerateChanges::class)->shouldReceive('handle')->once()->andReturnUsing(function () use (&$start): array {
            // What the command's signal handler does while the model call waits.
            $start->interrupt(SIGTERM);
            expect($this->task->fresh()->stop_requested_at)->not->toBeNull();

            return ['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]];
        });
        $this->mock(VerifyChanges::class)->shouldNotReceive('handle');
        $this->mock(ReviewChanges::class)->makePartial()->shouldNotReceive('handle');
        $start = app(StartTask::class);

        $run = $start->handle($this->task->id);

        expect($run->status)->toBe('stopped', json_encode($run->report))
            ->and($run->report['error'])->toStartWith('RUN_STOPPED:')
            ->and($run->report['stop_reason'])->toBe('Molly received SIGTERM and stopped at the next step.')
            ->and($run->report['changes'])->toBe([])
            ->and($this->task->fresh()->status)->toBe('stopped')
            ->and($this->task->fresh()->worker_id)->toBeNull()
            ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe('<?php return null;');
    });
});
