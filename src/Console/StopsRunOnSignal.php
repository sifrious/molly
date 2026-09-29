<?php

namespace Sifrious\Molly\Console;

use Sifrious\Molly\Actions\RetryTask;
use Sifrious\Molly\Actions\StartTask;

use function Laravel\Prompts\warning;

/**
 * Ctrl-C (SIGINT) or SIGTERM while a command runs a task asks the action to stop the attempt
 * instead of ending the process at once, so the run is saved as stopped and the processes it
 * started are terminated. The command then exits with 128 plus the signal number: 130 for
 * SIGINT and 143 for SIGTERM. A signal that arrives before the action starts ends the command
 * with that code right away.
 */
trait StopsRunOnSignal
{
    private StartTask|RetryTask|null $runningAction = null;

    private ?int $receivedSignal = null;

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return extension_loaded('pcntl') && defined('SIGINT') ? [SIGINT, SIGTERM] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $first = $this->receivedSignal === null;
        $this->receivedSignal ??= $signal;
        if ($this->runningAction === null) {
            return 128 + $this->receivedSignal;
        }
        if ($first && ! $this->option('json')) {
            warning('Stopping at the next step. Molly records the run as stopped.');
        }
        $this->runningAction->interrupt($signal);

        return false;
    }

    /** The exit code after the action returned or failed: 128 plus the signal when a signal stopped the run. */
    private function exitCodeAfterSignal(int $code, ?string $runStatus = null): int
    {
        return $this->receivedSignal !== null && in_array($runStatus, [null, 'stopped'], true) ? 128 + $this->receivedSignal : $code;
    }
}
