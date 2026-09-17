<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Sifrious\Molly\Actions\ExportTaskJournal;
use Sifrious\Molly\Actions\RefreshProjectJournal;
use Sifrious\Molly\Models\Task;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyJournalCommand extends Command
{
    protected $signature = 'molly:journal {task : Saved task name or ID} {--project : Refresh the workspace journal and glossary} {--json : Print JSON only}';

    protected $description = 'Export saved task evidence to a local Markdown journal';

    public function handle(ExportTaskJournal $action, RefreshProjectJournal $refresh): int
    {
        try {
            if ($this->option('project')) {
                $task = Task::findByReference((string) $this->argument('task'))
                    ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
                $result = ['task_id' => $task->id, ...$refresh->handle($task)->journal_status];
                if ($this->option('json')) {
                    $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
                } elseif ($result['status'] === 'written') {
                    note('Project journal saved: '.$result['journal_path']);
                    note('Project glossary saved: '.$result['glossary_path']);
                } else {
                    error('Project journal unavailable. '.$result['reason']);
                }

                return $result['status'] === 'written' ? self::SUCCESS : self::FAILURE;
            }

            $result = $action->handle((string) $this->argument('task'));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                note('Journal saved: '.$result['path']);
                note($result['attempt_count'].' saved '.($result['attempt_count'] === 1 ? 'attempt.' : 'attempts.'));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['task' => (string) $this->argument('task'), 'status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
