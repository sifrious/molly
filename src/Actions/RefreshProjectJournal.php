<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Models\Task;
use Throwable;

class RefreshProjectJournal
{
    public function __construct(private ExportTaskJournal $export) {}

    public function handle(Task $task): Task
    {
        try {
            $paths = $this->export->forWorkspace($task->workspace);
            $status = [
                'status' => 'written',
                'journal_path' => $paths['journal_path'],
                'glossary_path' => $paths['glossary_path'],
            ];
        } catch (Throwable $exception) {
            $status = ['status' => 'unavailable', 'reason' => $exception->getMessage()];
        }

        $status['checked_at'] = now()->toIso8601String();
        Task::withoutTimestamps(fn (): bool => $task->update(['journal_status' => $status]));

        return $task;
    }
}
