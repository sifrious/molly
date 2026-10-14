<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Acceptance\StageBloomHostScenario;
use Throwable;

use function Laravel\Prompts\note;

class MollyHostScenarioCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:host-scenario
        {action : stage or restore}
        {scenario? : incompat or broken, required for stage}
        {--plugins= : Bloom plugins directory. Defaults to BLOOM_PLUGINS_DIR or ~/Library/Application Support/Bloom/Plugins}
        {--json : Print JSON only}';

    protected $description = 'Stage or restore a Bloom plugin install for M04.13 and M04.15';

    public function handle(StageBloomHostScenario $scenarios): int
    {
        try {
            $action = (string) $this->argument('action');
            $plugins = $this->option('plugins');
            $plugins = is_string($plugins) && $plugins !== '' ? $plugins : null;
            $result = match ($action) {
                'stage' => $scenarios->stage((string) $this->argument('scenario'), $plugins),
                'restore' => $scenarios->restore($plugins),
                default => throw new \InvalidArgumentException('HOST_SCENARIO_ACTION_INVALID: Use stage or restore.'),
            };
            if ($this->option('json')) {
                $this->writeJson($result);
            } else {
                note($result['status'].($result['scenario'] !== null ? ' '.$result['scenario'] : '').' at '.$result['installed']);
                foreach ($result['next'] as $step) {
                    $this->line('  '.$step);
                }
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['status' => 'error', 'error' => $exception->getMessage()]);
        }
    }
}
