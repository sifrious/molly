<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Sifrious\Molly\Models\Task;

class LinkTaskThread
{
    /** @return array{id: int, task_id: string, thread_id: string, linked_at: string, source: string} */
    public function handle(string $reference, string $threadId): array
    {
        $task = Task::findByReference($reference)
            ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        $threadId = trim($threadId);
        if (! preg_match('/\AT-[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/i', $threadId)) {
            throw new RuntimeException('AMP_THREAD_INVALID: Enter an Amp thread ID beginning with T-.');
        }
        $threadId = 'T-'.strtolower(substr($threadId, 2));

        return DB::transaction(function () use ($task, $threadId): array {
            Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $latest = DB::table('molly_task_threads')->where('thread_id', $threadId)->orderByDesc('id')->lockForUpdate()->first();
            if ($latest === null || $latest->task_id !== $task->id) {
                $id = DB::table('molly_task_threads')->insertGetId([
                    'task_id' => $task->id, 'thread_id' => $threadId, 'linked_at' => now(),
                ]);
                $latest = DB::table('molly_task_threads')->find($id);
            }

            return ['id' => $latest->id, 'task_id' => $latest->task_id, 'thread_id' => $latest->thread_id,
                'linked_at' => $latest->linked_at, 'source' => 'user'];
        });
    }
}
