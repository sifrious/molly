<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Sifrious\Molly\Actions\CreateTask;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;

class MollyCreateCommand extends Command
{
    protected $signature = 'molly:create {prompt? : What should Molly work on?} {--workspace= : Repository path} {--file=* : Repository-relative file Molly may change} {--test= : Repository-relative Pest test file} {--json : Print JSON only}';

    protected $description = 'Save a task without running the model or changing files';

    public function handle(CreateTask $action, TaskReport $report): int
    {
        try {
            $prompt = trim((string) $this->argument('prompt'));
            if ($prompt === '') {
                if ($this->option('json') || ! $this->input->isInteractive()) {
                    throw new InvalidArgumentException('Provide a prompt when using --json or --no-interaction.');
                }
                $prompt = text('What should Molly work on?', required: 'Describe the change Molly should make.', transform: fn (string $value): string => trim($value));
            }
            $task = $action->handle($prompt, (string) ($this->option('workspace') ?: base_path()), $this->option('file'), trim((string) $this->option('test')));
            if ($this->option('json')) {
                $this->line(json_encode(['id' => $task->id, 'status' => $task->status, 'task' => $task->toArray()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                $report->show($task);
                note('Start this task with php artisan molly:start '.$task->id.'.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['id' => null, 'status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
