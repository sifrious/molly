<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\ImportGitHubIssue;
use Sifrious\Molly\Models\Task;
use Throwable;

use function Laravel\Prompts\note;

class MollyImportCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:import {issue : GitHub issue URL} {--workspace= : Repository path} {--file=* : Repository-relative file Molly may change} {--test= : Pest test file that must pass} {--allow-test-edits : Permit this task to change the required Pest test} {--todos : Write one Pest todo per acceptance criterion to the new test file} {--name= : Task nickname} {--json : Print JSON only}';

    protected $description = 'Read a GitHub issue and save a pending task';

    public function handle(ImportGitHubIssue $action, TaskReport $report): int
    {
        try {
            $task = $this->offeringChoices(fn (): Task => $action->handle((string) $this->argument('issue'), (string) ($this->option('workspace') ?: base_path()), $this->option('file'), trim((string) $this->option('test')), nickname: $this->option('name') === null ? null : (string) $this->option('name'), allowTestEdits: (bool) $this->option('allow-test-edits'), todos: (bool) $this->option('todos')));
            if ($this->option('json')) {
                $this->writeJson(['id' => $task->id, 'status' => $task->status, 'task' => $task->toArray()]);
            } else {
                $report->show($task);
                if ($this->option('todos')) {
                    note('Wrote '.count($task->source['acceptance']['criteria']).' Pest todos to '.$task->test_path.'. The test-authoring run replaces them with executable tests.');
                }
                note('Start this task with php artisan molly:start '.$task->reference().'.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportException($exception, ['id' => null, 'status' => 'error']);
        }
    }
}
