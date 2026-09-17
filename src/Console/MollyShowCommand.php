<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Sifrious\Molly\Actions\ShowRun;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;

class MollyShowCommand extends Command
{
    protected $signature = 'molly:show {run : Saved run ID} {--json : Print JSON only}';

    protected $description = 'Read a saved run report without rerunning the task';

    public function handle(ShowRun $action, RunReport $report): int
    {
        try {
            $run = $action->handle((string) $this->argument('run'));
            if ($run === null) {
                throw new RuntimeException('RUN_NOT_FOUND: No saved run has that ID.');
            }
            if ($this->option('json')) {
                $this->line(json_encode(['id' => $run->id, 'status' => $run->status, 'report' => $run->report], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                intro('Saved Molly run');
                $report->show($run);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['id' => (string) $this->argument('run'), 'status' => 'error', 'report' => ['error' => $exception->getMessage()]], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
