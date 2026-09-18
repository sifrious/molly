<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\ImportGitHubIssue;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyImportCommand extends Command
{
    protected $signature = 'molly:import {issue : GitHub issue URL} {--workspace= : Repository path} {--file=* : Repository-relative file Molly may change} {--test= : Pest test file that must pass} {--allow-test-edits : Permit this task to change the required Pest test} {--name= : Task nickname} {--json : Print JSON only}';

    protected $description = 'Read a GitHub issue and save a pending task';

    public function handle(ImportGitHubIssue $action, TaskReport $report): int
    {
        try {
            $task = $action->handle((string) $this->argument('issue'), (string) ($this->option('workspace') ?: base_path()), $this->option('file'), trim((string) $this->option('test')), nickname: $this->option('name') === null ? null : (string) $this->option('name'), allowTestEdits: (bool) $this->option('allow-test-edits'));
            if ($this->option('json')) {
                $this->line(json_encode(['id' => $task->id, 'status' => $task->status, 'task' => $task->toArray()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                $report->show($task);
                note('Start this task with php artisan molly:start '.$task->reference().'.');
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
