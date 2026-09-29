<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Sifrious\Molly\Actions\ExportTaskJournal;
use Sifrious\Molly\Actions\RefreshProjectJournal;
use Sifrious\Molly\Models\Task;
use Throwable;

use function Laravel\Prompts\note;

class MollyJournalCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:journal
        {task? : Saved task name or ID (optional with --project)}
        {--project : Refresh the workspace journal and glossary}
        {--workspace= : Workspace for --project without a task (default: current app)}
        {--json : Print JSON only}';

    protected $description = 'Export saved task evidence to a local Markdown journal';

    public function handle(ExportTaskJournal $action, RefreshProjectJournal $refresh): int
    {
        try {
            $reference = $this->argument('task');
            if ($this->option('project')) {
                if (is_string($reference) && $reference !== '') {
                    $task = Task::findByReference($reference)
                        ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
                    // With a task, --project also writes the task journal that molly:journal TASK writes.
                    $journal = $action->handle($task->id);
                    $result = ['task_id' => $task->id, 'path' => $journal['path'], 'attempt_count' => $journal['attempt_count'], ...$refresh->handle($task)->journal_status];
                } else {
                    $result = ['task_id' => null, ...$refresh->forWorkspace((string) ($this->option('workspace') ?: base_path()))];
                }
                if ($result['status'] !== 'written') {
                    // Keep the documented journal status keys on stdout; the code goes to stderr.
                    return $this->reportFailure((string) $result['reason'], $result);
                }
                if ($this->option('json')) {
                    $this->writeJson($result);
                } else {
                    if (isset($result['path'])) {
                        note('Journal saved: '.$result['path']);
                    }
                    note('Project journal saved: '.$result['journal_path']);
                    note('Project glossary saved: '.$result['glossary_path']);
                }

                return self::SUCCESS;
            }

            if (! is_string($reference) || $reference === '') {
                throw new RuntimeException('TASK_REQUIRED: Name a task to export its journal, or pass --project to refresh the workspace journal and glossary.');
            }
            $result = $action->handle($reference);
            if ($this->option('json')) {
                $this->writeJson($result);
            } else {
                note('Journal saved: '.$result['path']);
                note($result['attempt_count'].' saved '.($result['attempt_count'] === 1 ? 'attempt.' : 'attempts.'));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['task' => (string) $this->argument('task'), 'status' => 'error', 'error' => $exception->getMessage()]);
        }
    }
}
