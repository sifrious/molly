<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\StopTask;
use Throwable;

class MollyStopCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:stop {task : Saved task name or ID} {--json : Print JSON only}';

    protected $description = 'Stop a pending task or request a stop at the next execution boundary';

    public function handle(StopTask $action, TaskReport $report): int
    {
        try {
            $task = $action->handle((string) $this->argument('task'));
            if ($this->option('json')) {
                $this->writeJson(['id' => $task->id, 'status' => $task->status, 'task' => $task->toArray()]);
            } else {
                $report->show($task);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['id' => (string) $this->argument('task'), 'status' => 'error', 'error' => $exception->getMessage()]);
        }
    }
}
