<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Sifrious\Molly\Actions\InspectTask;
use Sifrious\Molly\Actions\ShowTask;
use Throwable;

use function Laravel\Prompts\error;

class MollyTaskCommand extends Command
{
    protected $signature = 'molly:task {task : Saved task name or ID} {--json : Print JSON only}';

    protected $description = 'Read a saved task and its run history';

    public function handle(ShowTask $action, InspectTask $inspect, TaskReport $report): int
    {
        try {
            $task = $action->handle((string) $this->argument('task'));
            if ($task === null) {
                throw new RuntimeException('TASK_NOT_FOUND: No saved task has that ID.');
            }
            $inspection = $inspect->handle($task);
            if ($this->option('json')) {
                $this->line(json_encode([
                    'id' => $task->id,
                    'status' => $task->status,
                    'display_status' => $inspection['display_status'],
                    'linked_pr' => $inspection['linked_pr'],
                    'issue_url' => $inspection['issue_url'],
                    'task' => $task->toArray(),
                ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                $report->show($task, $inspection);
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
