<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Acceptance\HostObservationRecorder;

use function Laravel\Prompts\note;

class MollyHostObserveCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:host-observe
        {kind : project, task, restart, or diagnostics}
        {--project= : Project directory the host showed, or the directory for the host log}
        {--task= : Task id the plugin or the CLI read}
        {--source= : plugin or cli. Defaults to plugin when MOLLY_HOST_READER=plugin, otherwise cli}
        {--json : Print JSON only}';

    protected $description = 'Record a Bloom host observation molly:verify can read';

    public function handle(HostObservationRecorder $recorder): int
    {
        $kind = (string) $this->argument('kind');
        $project = $this->option('project');
        $project = is_string($project) ? $project : '';
        $task = $this->option('task');
        $task = is_string($task) ? $task : '';
        if ($project === '' || ! is_dir($project)) {
            return $this->reportFailure('PROJECT_REQUIRED: Pass --project with a project directory.', [
                'status' => 'error',
                'error' => 'PROJECT_REQUIRED',
            ]);
        }
        $reader = $this->reader();
        $result = match ($kind) {
            'project' => $recorder->recordProject($project, $reader),
            'task' => $recorder->recordTask($project, $task, $reader),
            'restart' => $recorder->recordRestart($project, $task, $reader),
            'diagnostics' => $recorder->recordDiagnostics($project),
            default => null,
        };
        if ($result === null) {
            return $this->reportFailure('HOST_OBSERVE_KIND_INVALID: Use project, task, restart, or diagnostics.', [
                'status' => 'error',
                'error' => 'HOST_OBSERVE_KIND_INVALID',
            ]);
        }
        if ($this->option('json')) {
            $this->writeJson(['status' => 'ok', ...$result]);
        } else {
            foreach ($result['records'] as $record) {
                note($record['check'].' recorded '.$record['path']);
            }
            foreach ($result['skipped'] as $skipped) {
                note($skipped['check'].' was not recorded ('.$skipped['reason'].').');
            }
        }

        return self::SUCCESS;
    }

    private function reader(): string
    {
        $option = $this->option('source');
        if (is_string($option) && $option !== '') {
            return $option;
        }
        $env = getenv('MOLLY_HOST_READER');

        return $env === 'plugin' ? 'plugin' : 'cli';
    }
}
