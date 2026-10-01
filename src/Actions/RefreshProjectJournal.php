<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Models\Task;
use Throwable;

class RefreshProjectJournal
{
    public function __construct(private ExportTaskJournal $export) {}

    public function handle(Task $task): Task
    {
        $status = $this->forWorkspace($task->workspace);
        Task::withoutTimestamps(fn (): bool => $task->update(['journal_status' => $status]));

        return $task;
    }

    /**
     * Write `.molly/JOURNAL.md` and `.molly/GLOSSARY.md` for a workspace. Works when the
     * workspace has no saved tasks yet; a failure is reported, not thrown.
     *
     * @return array{status: string, journal_path?: string, glossary_path?: string, reason?: string, checked_at: string}
     */
    public function forWorkspace(string $workspace): array
    {
        try {
            $paths = $this->export->forWorkspace($workspace);
            $status = [
                'status' => 'written',
                'journal_path' => $paths['journal_path'],
                'glossary_path' => $paths['glossary_path'],
            ];
        } catch (Throwable $exception) {
            $status = ['status' => 'unavailable', 'reason' => $exception->getMessage()];
        }

        $status['checked_at'] = now()->toIso8601String();

        return $status;
    }
}
