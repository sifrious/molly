<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Sifrious\Molly\Actions\ListTasks;
use Sifrious\Molly\Models\Task;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

class MollyTasksCommand extends Command
{
    protected $signature = 'molly:tasks {--limit=20 : Maximum tasks to show} {--json : Print JSON only}';

    protected $description = 'List saved tasks with the newest first';

    public function handle(ListTasks $action): int
    {
        try {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($limit === false) {
                throw new InvalidArgumentException('Use --limit with a positive whole number.');
            }
            $tasks = $action->handle($limit);
            if ($this->option('json')) {
                $this->line(json_encode(['tasks' => $tasks->toArray()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } elseif ($tasks->isEmpty()) {
                note('No tasks yet. Create a task with php artisan molly:create.');
            } else {
                table(['Task', 'Status', 'Prompt'], $tasks->map(fn (Task $task): array => [$task->id, $task->status, $task->prompt])->all());
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['tasks' => [], 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
