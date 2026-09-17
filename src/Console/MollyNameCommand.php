<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Sifrious\Molly\Actions\NameTask;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;

class MollyNameCommand extends Command
{
    protected $signature = 'molly:name {task : Saved task name or ID} {name? : Short name for the task} {--json : Print JSON only}';

    protected $description = 'Give a saved task a name to use in commands';

    public function handle(NameTask $action, TaskReport $report): int
    {
        try {
            $nickname = trim((string) $this->argument('name'));
            if ($nickname === '') {
                if ($this->option('json') || ! $this->input->isInteractive()) {
                    throw new InvalidArgumentException('Provide a task name when using --json or --no-interaction.');
                }
                $nickname = text('What should this task be called?', placeholder: 'health-check', required: 'Enter a task name.');
            }

            $task = $action->handle((string) $this->argument('task'), $nickname);
            if ($this->option('json')) {
                $this->line(json_encode(['id' => $task->id, 'status' => $task->status, 'task' => $task->toArray()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                $report->show($task);
                note('Read this task with php artisan molly:task '.$task->reference().'.');
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
