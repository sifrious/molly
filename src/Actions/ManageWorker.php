<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Sifrious\Molly\Workspace;
use Sifrious\Molly\Workspace\Directory;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Start, stop, and inspect the one queue worker Molly owns in a workspace.
 *
 * The worker runs `php artisan queue:work` in the host application as its own
 * process group. Its pid, process group, and command line are recorded in
 * .molly/worker/worker.json. A recorded pid only counts as Molly's worker while
 * the process is alive, still leads the recorded group, and still runs the
 * recorded command line, so a reused pid is never signalled. Start and restart
 * first settle tasks that a killed worker or a restarted host left running.
 */
class ManageWorker
{
    public const DEFAULT_STOP_TIMEOUT = 30;

    /** Seconds molly:worker start waits for another molly:worker command to release worker.lock. */
    public const DEFAULT_LOCK_WAIT = 10;

    /**
     * `set -m` puts the background job in its own process group. The shell first closes
     * every descriptor above stderr that it inherited from the Artisan command, such as
     * the caller's stdout pipe or worker.lock, then points the worker's stdio at the log.
     * The shell prints the worker pid and returns at once, and the worker outlives the
     * command without holding the caller's pipe open.
     */
    private const LAUNCH_SCRIPT = <<<'SH'
        set -m
        for fd in $(ls /dev/fd); do
            case "$fd" in 0|1|2) ;; *) eval "exec $fd>&-" 2>/dev/null ;; esac
        done
        "$@" </dev/null >>"$MOLLY_WORKER_LOG" 2>&1 &
        echo $!
        SH;

    public function __construct(private RecoverAbandonedTasks $recovery) {}

    /** @return array<string, mixed> */
    public function status(string $workspace): array
    {
        $root = (new Workspace($workspace))->path;
        $record = $this->readRecord($root);
        $state = $this->inspect($record);

        return $this->report($root, 'status', $record, $state);
    }

    /** @return array<string, mixed> */
    public function start(string $workspace, int $lockWait = self::DEFAULT_LOCK_WAIT): array
    {
        $root = (new Workspace($workspace))->path;

        return $this->locked($root, $lockWait, function () use ($root): array {
            $record = $this->readRecord($root);
            $state = $this->inspect($record);
            if ($state['state'] === 'running') {
                throw new RuntimeException('WORKER_ALREADY_RUNNING: Molly already runs a worker for this workspace as pid '.$record['pid'].'. Stop it with php artisan molly:worker stop.');
            }
            $replaced = $state['state'] === 'stale' ? $state['stale_reason'] : null;
            File::delete($this->recordPath($root));

            $recovery = $this->recoverAbandoned();
            $record = $this->launch($root);

            return [...$this->report($root, 'start', $record, $this->inspect($record)), 'replaced_stale' => $replaced, 'recovery' => $recovery];
        });
    }

    /** @return array<string, mixed> */
    public function stop(string $workspace, int $timeout = self::DEFAULT_STOP_TIMEOUT): array
    {
        $root = (new Workspace($workspace))->path;

        return $this->locked($root, $timeout, fn (): array => $this->stopLocked($root, $timeout));
    }

    /** @return array<string, mixed> */
    public function restart(string $workspace, int $timeout = self::DEFAULT_STOP_TIMEOUT): array
    {
        $root = (new Workspace($workspace))->path;

        return $this->locked($root, $timeout, function () use ($root, $timeout): array {
            $stopped = $this->stopLocked($root, $timeout);
            $recovery = $this->recoverAbandoned();
            $record = $this->launch($root);

            return [...$this->report($root, 'restart', $record, $this->inspect($record)), 'stop' => $stopped, 'recovery' => $recovery];
        });
    }

    /**
     * Settle tasks a killed worker or a restarted host left running before a new worker starts.
     * The queue worker also runs the host application's own jobs, so a failed check is reported
     * with its reason and does not stop the worker from starting.
     *
     * @return array{status: string, recovered: list<array<string, string>>, limit_reached: bool, error?: string}
     */
    private function recoverAbandoned(): array
    {
        try {
            return ['status' => 'checked', ...$this->recovery->handle()];
        } catch (Throwable $exception) {
            return ['status' => 'failed', 'recovered' => [], 'limit_reached' => false, 'error' => 'WORKER_RECOVERY_FAILED: Molly could not check for tasks a previous worker left running. '.$exception->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function stopLocked(string $root, int $timeout): array
    {
        if ($timeout < 1 || $timeout > 3600) {
            throw new RuntimeException('WORKER_TIMEOUT_INVALID: Use --timeout with a whole number of seconds from 1 to 3600.');
        }

        $record = $this->readRecord($root);
        $state = $this->inspect($record);
        $signal = null;

        if ($state['state'] === 'running') {
            $group = -$record['pgid'];
            posix_kill($group, SIGTERM);
            $signal = 'SIGTERM';
            if (! $this->waitForExit($record['pid'], $timeout)) {
                posix_kill($group, SIGKILL);
                $signal = 'SIGKILL';
                if (! $this->waitForExit($record['pid'], 5)) {
                    throw new RuntimeException('WORKER_STOP_FAILED: Process '.$record['pid'].' is still running after SIGKILL.');
                }
            }
        }

        File::delete($this->recordPath($root));

        return [
            ...$this->report($root, 'stop', null, ['state' => 'stopped', 'alive' => false, 'stale_reason' => null]),
            'previous_state' => $state['state'],
            'previous_pid' => $record['pid'] ?? null,
            'stale_reason' => $state['stale_reason'],
            'signal' => $signal,
        ];
    }

    /** @return array{pid: int, pgid: int, command: list<string>, connection: string, queue: string, started_at: string} */
    private function launch(string $root): array
    {
        [$connection, $queue] = $this->queue();
        $command = [$this->phpBinary(), base_path('artisan'), 'queue:work', $connection, '--queue='.$queue];
        $directory = $this->directory($root);
        $this->assertWritable($directory);
        $logOffset = is_file($directory.'/worker.log') ? (int) filesize($directory.'/worker.log') : 0;
        @touch($directory.'/worker.log');
        @chmod($directory.'/worker.log', 0600);

        $result = Process::path(base_path())
            ->env(['MOLLY_WORKER_LOG' => $directory.'/worker.log'])
            ->timeout(15)
            ->run(['/bin/sh', '-c', self::LAUNCH_SCRIPT, 'molly-worker', ...$command]);
        $pid = (int) trim($result->output());
        if (! $result->successful() || $pid < 2) {
            throw new RuntimeException('WORKER_START_FAILED: Molly could not launch the queue worker. '.trim($result->errorOutput()));
        }

        $record = ['pid' => $pid, 'pgid' => $pid, 'command' => $command, 'connection' => $connection, 'queue' => $queue, 'started_at' => Carbon::now()->toISOString()];

        // Wait for the shell to exec the worker and for an immediate failure to surface.
        usleep(300_000);
        $deadline = microtime(true) + 3;
        while (($state = $this->inspect($record))['state'] !== 'running' && $state['stale_reason'] !== 'process_gone' && microtime(true) < $deadline) {
            usleep(100_000);
        }
        if ($state['state'] !== 'running') {
            if ($state['alive']) {
                posix_kill(-$pid, SIGKILL);
            }
            throw new RuntimeException('WORKER_START_FAILED: The queue worker exited or did not start. Worker log: '.$this->logExcerpt($directory.'/worker.log', $logOffset));
        }

        try {
            File::put($this->recordPath($root), json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
            @chmod($this->recordPath($root), 0600);
        } catch (Throwable $exception) {
            // An unrecorded worker is invisible to status and stop, so it must not outlive this command.
            posix_kill(-$pid, SIGKILL);
            $this->waitForExit($pid, 5);
            throw new RuntimeException('WORKER_START_FAILED: Molly stopped the new worker because it could not write '.$this->recordPath($root).'. '.$exception->getMessage(), 0, $exception);
        }

        return $record;
    }

    /**
     * Refuse to launch when Molly could not record the worker: an unrecorded worker keeps
     * running where molly:worker status and stop cannot see it.
     */
    private function assertWritable(string $directory): void
    {
        foreach ([$directory, $directory.'/worker.json', $directory.'/worker.log'] as $path) {
            if (file_exists($path) ? ! is_writable($path) : ! is_writable($directory)) {
                throw new RuntimeException('WORKER_START_FAILED: Molly cannot write '.$path.'. Make '.$directory.' writable by this user, then start the worker again.');
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $record
     * @return array{state: string, alive: bool, stale_reason: string|null}
     */
    private function inspect(?array $record): array
    {
        if ($record === null) {
            return ['state' => 'stopped', 'alive' => false, 'stale_reason' => null];
        }

        $pid = $record['pid'];
        if (! posix_kill($pid, 0)) {
            // EPERM means a process exists but belongs to another user: not Molly's worker.
            return posix_get_last_error() === 1
                ? ['state' => 'stale', 'alive' => true, 'stale_reason' => 'pid_reused']
                : ['state' => 'stale', 'alive' => false, 'stale_reason' => 'process_gone'];
        }

        $expected = implode(' ', array_slice($record['command'], 1));
        $owned = str_contains($this->commandLine($pid), $expected) && posix_getpgid($pid) === $record['pgid'];

        return $owned
            ? ['state' => 'running', 'alive' => true, 'stale_reason' => null]
            : ['state' => 'stale', 'alive' => true, 'stale_reason' => 'pid_reused'];
    }

    /**
     * The process's arguments joined by spaces. Linux keeps the exact bytes in /proc. Elsewhere ps
     * prints non-ASCII bytes as escapes unless the locale is UTF-8, and an app opened from the Finder
     * has no locale set, so ps runs with en_US.UTF-8, which every macOS release ships.
     */
    private function commandLine(int $pid): string
    {
        $arguments = @file_get_contents('/proc/'.$pid.'/cmdline');
        if (is_string($arguments) && $arguments !== '') {
            return str_replace("\0", ' ', rtrim($arguments, "\0"));
        }

        return Process::timeout(5)->env(['LC_ALL' => 'en_US.UTF-8'])->run(['ps', '-o', 'command=', '-p', (string) $pid])->output();
    }

    /**
     * @param  array<string, mixed>|null  $record
     * @param  array{state: string, alive: bool, stale_reason: string|null}  $state
     * @return array<string, mixed>
     */
    private function report(string $root, string $action, ?array $record, array $state): array
    {
        [$connection, $queue] = $this->queue();
        $running = $state['state'] === 'running';
        $started = $running ? Carbon::parse($record['started_at']) : null;

        return [
            'action' => $action,
            'workspace' => $root,
            'state' => $state['state'],
            'alive' => $running,
            'stale_reason' => $state['stale_reason'],
            'pid' => $record['pid'] ?? null,
            'pgid' => $record['pgid'] ?? null,
            'started_at' => $record['started_at'] ?? null,
            'uptime_seconds' => $started === null ? null : max(0, (int) $started->diffInSeconds(Carbon::now(), true)),
            'command' => $record['command'] ?? null,
            'connection' => $record['connection'] ?? $connection,
            'queue' => $record['queue'] ?? $queue,
            'pid_file' => $this->recordPath($root),
            'log' => $root.'/.molly/worker/worker.log',
        ];
    }

    /** @return array{pid: int, pgid: int, command: list<string>, connection: string, queue: string, started_at: string}|null */
    private function readRecord(string $root): ?array
    {
        $path = $this->recordPath($root);
        if (! is_file($path)) {
            return null;
        }

        $record = json_decode((string) file_get_contents($path), true);
        if (! is_array($record) || ! is_int($record['pid'] ?? null) || $record['pid'] < 2 || ! is_int($record['pgid'] ?? null)
            || ! is_array($record['command'] ?? null) || ! is_string($record['started_at'] ?? null)) {
            throw new RuntimeException('WORKER_RECORD_INVALID: '.$path.' is not a Molly worker record. Remove it after checking no worker is running.');
        }

        return $record;
    }

    /** @return array{0: string, 1: string} */
    private function queue(): array
    {
        $connection = (string) config('queue.default');

        return [$connection, (string) (config('queue.connections.'.$connection.'.queue') ?? 'default')];
    }

    private function phpBinary(): string
    {
        $binary = (string) (config('molly.worker.php_binary') ?: PHP_BINARY);
        $path = str_contains($binary, '/') ? $binary : (new ExecutableFinder)->find($binary);
        if ($path === null || ! is_file($path) || ! is_executable($path)) {
            throw new RuntimeException('WORKER_PHP_MISSING: molly.worker.php_binary is '.$binary.', which is not an executable file.');
        }

        return $path;
    }

    private function waitForExit(int $pid, int $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (posix_kill($pid, 0)) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(100_000);
        }

        return true;
    }

    private function directory(string $root): string
    {
        // The log and record can hold paths and queue output, so only this user may read them.
        // Drop group and other access from an older directory but keep the owner's bits.
        $directory = Directory::molly($root, 'worker');
        Directory::ensure($directory, 0700);
        @chmod($directory, fileperms($directory) & 0700);

        return $directory;
    }

    private function recordPath(string $root): string
    {
        return Directory::molly($root, 'worker/worker.json');
    }

    private function logExcerpt(string $path, int $offset): string
    {
        $contents = is_file($path) ? (string) file_get_contents($path, offset: $offset) : '';
        $lines = array_slice(array_values(array_filter(array_map('trim', explode("\n", $contents)), fn (string $line): bool => $line !== '')), 0, 5);

        return $lines === [] ? '(empty)' : implode(' | ', $lines);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(string $root, int $wait, callable $callback): mixed
    {
        $path = $this->directory($root).'/worker.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('WORKER_LOCK_FAILED: Molly could not open '.$path.'. Make '.dirname($path).' writable by this user.');
        }

        $deadline = microtime(true) + max(0, $wait);
        while (! flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            if (! $wouldBlock) {
                fclose($handle);
                throw new RuntimeException('WORKER_LOCK_FAILED: Molly could not lock '.$path.'.');
            }
            if (microtime(true) >= $deadline) {
                fclose($handle);
                throw new RuntimeException('WORKER_BUSY: Another molly:worker command has held '.$path.' for '.$wait.' seconds. Wait for it to finish, or pass a longer --timeout.');
            }
            usleep(100_000);
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
