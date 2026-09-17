<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Sifrious\Molly\Actions\ShowTask;
use Throwable;

use function Laravel\Prompts\error;

class MollyTaskCommand extends Command
{
    protected $signature = 'molly:task {task : Saved task ID} {--json : Print JSON only}';

    protected $description = 'Read a saved task and its run history';

    public function handle(ShowTask $action, TaskReport $report): int
    {
        try {
            $task = $action->handle((string) $this->argument('task'));
            if ($task === null) {
                throw new RuntimeException('TASK_NOT_FOUND: No saved task has that ID.');
            }
            if ($this->option('json')) {
                $this->line(json_encode(['id' => $task->id, 'status' => $task->status, 'task' => $task->toArray()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                $report->show($task);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['id' => (string) $this->argument('task'), 'status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
