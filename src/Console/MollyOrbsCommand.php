<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\ManageWorker;
use Sifrious\Molly\Execution\LocalOrbProvider;
use Sifrious\Molly\Models\Orb;
use Throwable;

use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class MollyOrbsCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:orbs
        {--check : Ask each active Orb\'s runtime for its health first, which also records a heartbeat}
        {--json : Print JSON only}';

    protected $description = 'List registered Orbs with availability, health, capabilities, repository, current task, and worker';

    public function handle(LocalOrbProvider $orbs, ManageWorker $worker): int
    {
        try {
            $rows = $orbs->all((bool) $this->option('check'))
                ->map(fn (Orb $orb): array => [...$orbs->describeOrb($orb), 'worker' => $this->worker($worker, $orb)])
                ->values()->all();
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['status' => 'error', 'error' => $exception->getMessage()]);
        }

        if ($this->option('json')) {
            $this->writeJson(['status' => 'ok', 'orbs' => $rows]);

            return self::SUCCESS;
        }
        if ($rows === []) {
            note('No Orbs are registered. Register one with php artisan molly:orb-register.');

            return self::SUCCESS;
        }

        table(['Orb', 'Availability', 'Health', 'Runtime / model', 'Current task', 'Worker'], array_map(fn (array $orb): array => [
            $orb['name'],
            $orb['availability'],
            $orb['health'],
            $orb['runtime'].' / '.($orb['model'] ?? 'chosen by Amp'),
            $orb['current_task'] === null ? 'idle' : $orb['current_task']['reference'].' ('.$orb['current_task']['status'].')',
            $orb['worker']['state'].($orb['worker']['pid'] === null ? '' : ' (pid '.$orb['worker']['pid'].')'),
        ], $rows));
        foreach ($rows as $orb) {
            note($orb['name'].' is '.$orb['id'].'. It may change '.$orb['repository']['path'].' in worktrees under '.$orb['worktree_root'].'.');
            if ($orb['health'] !== 'healthy' && $orb['health_reason'] !== null) {
                warning($orb['name'].': '.$orb['health_reason']);
            }
        }

        return self::SUCCESS;
    }

    /** @return array{state: string, pid: ?int, error?: string} the Orb's queue worker, as molly:worker status --orb reports it */
    private function worker(ManageWorker $worker, Orb $orb): array
    {
        try {
            $status = $worker->status(base_path(), $orb);

            return ['state' => $status['state'], 'pid' => $status['alive'] ? $status['pid'] : null];
        } catch (Throwable $exception) {
            return ['state' => 'unknown', 'pid' => null, 'error' => $exception->getMessage()];
        }
    }
}
