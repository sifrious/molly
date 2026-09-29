<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Sifrious\Molly\Actions\RecoverAbandonedTasks;
use Sifrious\Molly\Models\Task;
use Symfony\Component\Process\Process;

/** A stand-in for php that runs until signalled. With $ignoreTerm it only dies to SIGKILL. */
function fakeWorkerBinary(string $directory, bool $ignoreTerm = false): string
{
    $path = $directory.'/fake-php';
    File::put($path, "#!/bin/sh\n".($ignoreTerm ? "trap '' TERM\n" : "trap 'exit 0' TERM\n")."while :; do sleep 1; done\n");
    chmod($path, 0755);

    return $path;
}

/** @return array<string, mixed> */
function worker(string $action, string $workspace, array $options = []): array
{
    $exit = Artisan::call('molly:worker', ['action' => $action, '--workspace' => $workspace, '--json' => true, ...$options]);

    return ['exit' => $exit, ...json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

function processAlive(int $pid): bool
{
    return posix_kill($pid, 0);
}

beforeEach(function (): void {
    $this->workspace = sys_get_temp_dir().'/molly-worker-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->workspace);
    $this->workspace = realpath($this->workspace);
    config(['molly.worker.php_binary' => fakeWorkerBinary($this->workspace)]);
});

afterEach(function (): void {
    $record = $this->workspace.'/.molly/worker/worker.json';
    if (is_file($record)) {
        $data = json_decode(File::get($record), true);
        if (is_int($data['pgid'] ?? null) && $data['pgid'] !== posix_getpgrp()) {
            @posix_kill(-$data['pgid'], SIGKILL);
        }
    }
    File::deleteDirectory($this->workspace);
});

it('starts a worker in its own process group, reports it, and stops it with SIGTERM', function (): void {
    $started = worker('start', $this->workspace);

    expect($started['exit'])->toBe(0)
        ->and($started['status'])->toBe('ok')
        ->and($started['state'])->toBe('running')
        ->and($started['alive'])->toBeTrue()
        ->and($started['pgid'])->toBe($started['pid'])
        ->and($started['command'])->toContain('queue:work', '--queue='.$started['queue'])
        ->and(processAlive($started['pid']))->toBeTrue()
        ->and(posix_getpgid($started['pid']))->toBe($started['pid'])
        ->and(json_decode(File::get($this->workspace.'/.molly/worker/worker.json'), true)['pid'])->toBe($started['pid']);

    sleep(1);
    $status = worker('status', $this->workspace);
    expect($status['exit'])->toBe(0)
        ->and($status['state'])->toBe('running')
        ->and($status['pid'])->toBe($started['pid'])
        ->and($status['uptime_seconds'])->toBeGreaterThanOrEqual(1);

    $stopped = worker('stop', $this->workspace, ['--timeout' => 5]);
    expect($stopped['exit'])->toBe(0)
        ->and($stopped['signal'])->toBe('SIGTERM')
        ->and($stopped['previous_state'])->toBe('running')
        ->and($stopped['state'])->toBe('stopped')
        ->and(processAlive($started['pid']))->toBeFalse()
        ->and(File::exists($this->workspace.'/.molly/worker/worker.json'))->toBeFalse();

    expect(worker('status', $this->workspace))->toMatchArray(['exit' => 0, 'state' => 'stopped', 'alive' => false, 'pid' => null]);
});

it('recognizes its worker in a path with spaces and Unicode when the locale is not UTF-8', function (): void {
    // ps prints non-ASCII bytes as escapes under the C locale, which apps opened from the Finder
    // get. base_path() is fixed in tests, so the queue name carries the non-ASCII characters.
    $workspace = $this->workspace.'/Molly Tëst ✓/app';
    File::ensureDirectoryExists($workspace);
    config(['molly.worker.php_binary' => fakeWorkerBinary($workspace), 'queue.connections.sync.queue' => 'Tëst ✓']);
    $locale = ['env' => getenv('LC_ALL'), 'server' => $_SERVER['LC_ALL'] ?? null, 'dotenv' => $_ENV['LC_ALL'] ?? null];
    putenv('LC_ALL=C');
    $_SERVER['LC_ALL'] = $_ENV['LC_ALL'] = 'C';

    try {
        $started = worker('start', $workspace);
        expect($started['exit'])->toBe(0, (string) ($started['error'] ?? ''))
            ->and($started['state'])->toBe('running')
            ->and($started['command'])->toContain('--queue=Tëst ✓')
            ->and($started['log'])->toBe($workspace.'/.molly/worker/worker.log')
            ->and(worker('status', $workspace))->toMatchArray(['state' => 'running', 'pid' => $started['pid']]);

        $stopped = worker('stop', $workspace, ['--timeout' => 5]);
        expect($stopped['signal'])->toBe('SIGTERM')
            ->and(processAlive($started['pid']))->toBeFalse();
    } finally {
        $locale['env'] === false ? putenv('LC_ALL') : putenv('LC_ALL='.$locale['env']);
        foreach (['server' => '_SERVER', 'dotenv' => '_ENV'] as $key => $global) {
            if ($locale[$key] === null) {
                unset($GLOBALS[$global]['LC_ALL']);
            } else {
                $GLOBALS[$global]['LC_ALL'] = $locale[$key];
            }
        }
        $record = $workspace.'/.molly/worker/worker.json';
        if (is_file($record)) {
            @posix_kill(-json_decode(File::get($record), true)['pgid'], SIGKILL);
        }
    }
});

it('refuses a second start while the owned worker is alive', function (): void {
    $first = worker('start', $this->workspace);
    $second = worker('start', $this->workspace);

    expect($second['exit'])->toBe(1)
        ->and($second['status'])->toBe('error')
        ->and($second['error'])->toStartWith('WORKER_ALREADY_RUNNING')
        ->and(processAlive($first['pid']))->toBeTrue()
        ->and(worker('status', $this->workspace)['pid'])->toBe($first['pid']);

    worker('stop', $this->workspace, ['--timeout' => 5]);
});

it('sends SIGKILL to the process group when SIGTERM does not stop it in time', function (): void {
    config(['molly.worker.php_binary' => fakeWorkerBinary($this->workspace, ignoreTerm: true)]);
    $started = worker('start', $this->workspace);

    $stopped = worker('stop', $this->workspace, ['--timeout' => 1]);

    expect($stopped['exit'])->toBe(0)
        ->and($stopped['signal'])->toBe('SIGKILL')
        ->and(processAlive($started['pid']))->toBeFalse();
});

it('restarts the worker with a new process', function (): void {
    $first = worker('start', $this->workspace);
    $restarted = worker('restart', $this->workspace, ['--timeout' => 5]);

    expect($restarted['exit'])->toBe(0)
        ->and($restarted['state'])->toBe('running')
        ->and($restarted['pid'])->not->toBe($first['pid'])
        ->and($restarted['stop']['signal'])->toBe('SIGTERM')
        ->and(processAlive($first['pid']))->toBeFalse()
        ->and(processAlive($restarted['pid']))->toBeTrue();

    worker('stop', $this->workspace, ['--timeout' => 5]);
});

it('treats a pid file for a process that has exited as stale and starts a new worker', function (): void {
    $gone = new Process(['true']);
    $gone->start();
    $pid = $gone->getPid();
    $gone->wait();
    File::ensureDirectoryExists($this->workspace.'/.molly/worker');
    File::put($this->workspace.'/.molly/worker/worker.json', json_encode([
        'pid' => $pid, 'pgid' => $pid, 'command' => ['php', base_path('artisan'), 'queue:work', 'sync', '--queue=default'],
        'connection' => 'sync', 'queue' => 'default', 'started_at' => now()->toISOString(),
    ]));

    expect(worker('status', $this->workspace))->toMatchArray(['state' => 'stale', 'stale_reason' => 'process_gone', 'alive' => false]);

    $started = worker('start', $this->workspace);
    expect($started['exit'])->toBe(0)
        ->and($started['state'])->toBe('running')
        ->and($started['replaced_stale'])->toBe('process_gone');

    worker('stop', $this->workspace, ['--timeout' => 5]);
});

it('never signals a reused pid that runs a different command', function (): void {
    $other = new Process(['sleep', '30']);
    $other->start();
    File::ensureDirectoryExists($this->workspace.'/.molly/worker');
    File::put($this->workspace.'/.molly/worker/worker.json', json_encode([
        'pid' => $other->getPid(), 'pgid' => posix_getpgid($other->getPid()), 'command' => ['php', base_path('artisan'), 'queue:work', 'sync', '--queue=default'],
        'connection' => 'sync', 'queue' => 'default', 'started_at' => now()->toISOString(),
    ]));

    try {
        expect(worker('status', $this->workspace))->toMatchArray(['state' => 'stale', 'stale_reason' => 'pid_reused', 'alive' => false]);

        $stopped = worker('stop', $this->workspace, ['--timeout' => 1]);
        expect($stopped['exit'])->toBe(0)
            ->and($stopped['signal'])->toBeNull()
            ->and($stopped['stale_reason'])->toBe('pid_reused')
            ->and($other->isRunning())->toBeTrue()
            ->and(File::exists($this->workspace.'/.molly/worker/worker.json'))->toBeFalse();
    } finally {
        $other->stop(0);
    }
});

it('reports a missing php binary without starting anything', function (): void {
    config(['molly.worker.php_binary' => $this->workspace.'/missing/php']);

    $result = worker('start', $this->workspace);

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('WORKER_PHP_MISSING')
        ->and($result['error'])->toContain($this->workspace.'/missing/php')
        ->and(File::exists($this->workspace.'/.molly/worker/worker.json'))->toBeFalse();
});

it('reports a worker that exits at once as a failed start', function (): void {
    $binary = $this->workspace.'/exits';
    File::put($binary, "#!/bin/sh\necho 'could not open artisan' >&2\nexit 1\n");
    chmod($binary, 0755);
    config(['molly.worker.php_binary' => $binary]);

    $result = worker('start', $this->workspace);

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('WORKER_START_FAILED')
        ->and($result['error'])->toContain('could not open artisan')
        ->and(File::exists($this->workspace.'/.molly/worker/worker.json'))->toBeFalse();
});

it('rejects an unknown action and an invalid timeout', function (): void {
    expect(worker('pause', $this->workspace))->toMatchArray(['exit' => 1, 'status' => 'error'])
        ->and(worker('pause', $this->workspace)['error'])->toStartWith('WORKER_ACTION_INVALID')
        ->and(worker('stop', $this->workspace, ['--timeout' => '0'])['error'])->toStartWith('WORKER_TIMEOUT_INVALID');
});

it('closes the caller pipe so a piped molly:worker start returns at once', function (): void {
    $command = testbenchProcess(['molly:worker', 'start', '--workspace='.$this->workspace, '--json'])->getCommandLine();
    $pipeline = Process::fromShellCommandline($command.' | cat', dirname(__DIR__, 2), [
        ...testbenchProcess([])->getEnv(),
        'MOLLY_WORKER_PHP_BINARY' => config('molly.worker.php_binary'),
    ], timeout: 15);

    $started = microtime(true);
    $pipeline->run();
    $result = json_decode($pipeline->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($pipeline->getExitCode())->toBe(0)
        ->and(microtime(true) - $started)->toBeLessThan(10)
        ->and($result['state'])->toBe('running')
        ->and(processAlive($result['pid']))->toBeTrue();
});

/** @return list<int> Live processes whose command line names $binary. */
function processesRunning(string $binary): array
{
    $found = new Process(['pgrep', '-f', $binary]);
    $found->run();

    return array_map(intval(...), array_filter(explode("\n", trim($found->getOutput()))));
}

it('refuses to start a worker it could not record and leaves no process behind', function (): void {
    $directory = $this->workspace.'/.molly/worker';
    File::ensureDirectoryExists($directory);
    File::put($directory.'/worker.lock', '');
    File::put($directory.'/worker.log', '');
    chmod($directory, 0555);

    try {
        $result = worker('start', $this->workspace);
    } finally {
        chmod($directory, 0755);
    }

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('WORKER_START_FAILED')
        ->and($result['error'])->toContain($directory)
        ->and(processesRunning(config('molly.worker.php_binary')))->toBe([])
        ->and(worker('status', $this->workspace)['state'])->toBe('stopped');
});

it('stops a worker that started but could not be recorded', function (): void {
    $directory = $this->workspace.'/.molly/worker';
    $binary = $this->workspace.'/locks-its-directory';
    File::put($binary, "#!/bin/sh\nchmod 555 '".$directory."'\ntrap 'exit 0' TERM\nwhile :; do sleep 1; done\n");
    chmod($binary, 0755);
    config(['molly.worker.php_binary' => $binary]);

    try {
        $result = worker('start', $this->workspace);
    } finally {
        chmod($directory, 0755);
    }

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('WORKER_START_FAILED')
        ->and($result['error'])->toContain($directory.'/worker.json')
        ->and(processesRunning($binary))->toBe([])
        ->and(worker('status', $this->workspace)['state'])->toBe('stopped');
});

it('reports WORKER_BUSY instead of waiting forever for worker.lock', function (): void {
    $lock = $this->workspace.'/.molly/worker/worker.lock';
    File::ensureDirectoryExists(dirname($lock));
    $holder = new Process([PHP_BINARY, '-r', '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); echo "locked\n"; sleep(8);', $lock]);
    $holder->start();
    $holder->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'locked'));

    try {
        $started = microtime(true);
        $result = worker('start', $this->workspace, ['--timeout' => 1]);
        $elapsed = microtime(true) - $started;
    } finally {
        $holder->stop(0);
    }

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('WORKER_BUSY')
        ->and($result['error'])->toContain($lock)
        ->and($elapsed)->toBeLessThan(5)
        ->and(File::exists($this->workspace.'/.molly/worker/worker.json'))->toBeFalse();
});

it('keeps the worker directory, log, and record private to the user', function (): void {
    worker('start', $this->workspace);

    expect(fileperms($this->workspace.'/.molly/worker') & 0777)->toBe(0700)
        ->and(fileperms($this->workspace.'/.molly/worker/worker.log') & 0777)->toBe(0600)
        ->and(fileperms($this->workspace.'/.molly/worker/worker.json') & 0777)->toBe(0600);

    worker('stop', $this->workspace, ['--timeout' => 5]);
});

it('settles tasks a killed worker left running before it starts a new worker', function (): void {
    $gone = new Process(['sleep', '30']);
    $gone->start();
    $pid = $gone->getPid();
    $gone->stop(0);
    $task = Task::create([
        'prompt' => 'Return Hello.', 'workspace' => $this->workspace, 'paths' => ['app/Greeting.php'], 'test_path' => 'tests/GreetingTest.php',
        'status' => 'running', 'attempt_number' => 1, 'worker_id' => gethostname().':'.$pid, 'claimed_at' => now()->subMinute(), 'lease_expires_at' => now()->addMinutes(5),
    ]);
    $run = $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $this->workspace, 'status' => 'running', 'report' => []]);

    $started = worker('start', $this->workspace);

    expect($started['exit'])->toBe(0)
        ->and($started['state'])->toBe('running')
        ->and($started['recovery'])->toBe(['status' => 'checked', 'recovered' => [
            ['task_id' => $task->id, 'reference' => $task->id, 'status' => 'failed', 'reason' => 'worker_exited'],
        ], 'limit_reached' => false])
        ->and($task->fresh()->status)->toBe('failed')
        ->and($run->fresh()->status)->toBe('failed')
        ->and($run->fresh()->report['error'])->toStartWith('RUN_ABANDONED:');

    $restarted = worker('restart', $this->workspace, ['--timeout' => 5]);
    expect($restarted['recovery'])->toBe(['status' => 'checked', 'recovered' => [], 'limit_reached' => false]);

    worker('stop', $this->workspace, ['--timeout' => 5]);
});

it('starts the worker and reports the reason when the recovery check fails', function (): void {
    $this->mock(RecoverAbandonedTasks::class)->shouldReceive('handle')->andThrow(new RuntimeException('SQLSTATE[HY000]: General error: 5 database is locked'));

    $started = worker('start', $this->workspace);

    expect($started['exit'])->toBe(0)
        ->and($started['state'])->toBe('running')
        ->and($started['recovery']['status'])->toBe('failed')
        ->and($started['recovery']['error'])->toBe('WORKER_RECOVERY_FAILED: Molly could not check for tasks a previous worker left running. SQLSTATE[HY000]: General error: 5 database is locked');

    worker('stop', $this->workspace, ['--timeout' => 5]);
});
