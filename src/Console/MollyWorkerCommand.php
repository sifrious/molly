<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Sifrious\Molly\Actions\ManageWorker;
use Sifrious\Molly\Execution\LocalOrbProvider;
use Throwable;

use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class MollyWorkerCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:worker
        {action : start, stop, status, or restart}
        {--workspace= : Workspace that records the worker under .molly/worker (defaults to the application)}
        {--orb= : Manage the worker for this Orb, by name or ID, which reads only that Orb\'s queue}
        {--timeout= : Seconds to wait for another molly:worker command to finish (default 10 for start), and after SIGTERM before SIGKILL when stopping (default 30)}
        {--json : Print JSON only}';

    protected $description = 'Start, stop, or inspect the queue worker Molly owns for this workspace or for one Orb';

    public function handle(ManageWorker $worker, LocalOrbProvider $orbs): int
    {
        $action = (string) $this->argument('action');

        try {
            $workspace = (string) ($this->option('workspace') ?: base_path());
            $orb = $this->option('orb') === null ? null : $orbs->find((string) $this->option('orb'));
            $result = match ($action) {
                'start' => $worker->start($workspace, $this->option('timeout') === null ? ManageWorker::DEFAULT_LOCK_WAIT : $this->timeout(), $orb),
                'stop' => $worker->stop($workspace, $this->timeout(), $orb),
                'restart' => $worker->restart($workspace, $this->timeout(), $orb),
                'status' => $worker->status($workspace, $orb),
                default => throw new RuntimeException('WORKER_ACTION_INVALID: Use start, stop, status, or restart.'),
            };
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['action' => $action, 'status' => 'error', 'error' => $exception->getMessage()]);
        }

        if ($this->option('json')) {
            $this->line(json_encode(['status' => 'ok', ...$result], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        table(['Field', 'Value'], [
            ...($result['orb'] === null ? [] : [['Orb', $result['orb']['name'].' ('.$result['orb']['id'].')']]),
            ['State', $result['state']],
            ['Pid', (string) ($result['pid'] ?? 'none')],
            ['Uptime', $result['uptime_seconds'] === null ? 'none' : $result['uptime_seconds'].'s'],
            ['Queue', $result['connection'].' / '.$result['queue']],
            ['Log', $result['log']],
        ]);
        if (($result['stale_reason'] ?? null) !== null) {
            note('The recorded worker is stale: '.$result['stale_reason'].'. Molly did not signal that process.');
        }
        if (isset($result['signal'])) {
            note('Stopped pid '.$result['previous_pid'].' with '.$result['signal'].'.');
        }
        $recovery = $result['recovery'] ?? null;
        if (($recovery['status'] ?? null) === 'failed') {
            warning($recovery['error']);
        }
        foreach ($recovery['recovered'] ?? [] as $task) {
            note('Recovered task '.$task['reference'].' as '.$task['status'].' ('.$task['reason'].'). Retry it with php artisan molly:retry '.$task['reference'].'.');
        }
        if ($recovery['limit_reached'] ?? false) {
            note('The recovery check stopped at its limit. Molly recovers the remaining tasks when they are started or retried, or at the next worker start.');
        }

        return self::SUCCESS;
    }

    private function timeout(): int
    {
        $option = $this->option('timeout');
        if ($option === null) {
            return ManageWorker::DEFAULT_STOP_TIMEOUT;
        }
        $timeout = filter_var($option, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3600]]);
        if ($timeout === false) {
            throw new RuntimeException('WORKER_TIMEOUT_INVALID: Use --timeout with a whole number of seconds from 1 to 3600.');
        }

        return $timeout;
    }
}
