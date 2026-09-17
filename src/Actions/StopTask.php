<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;

class StopTask
{
    public function handle(string $id): Task
    {
        Task::whereKey($id)->where('status', 'pending')->update([
            'status' => 'stopped',
            'stop_requested_at' => now(),
        ]);

        Task::whereKey($id)->where('status', 'running')->whereNull('stop_requested_at')->update([
            'stop_requested_at' => now(),
        ]);

        $task = Task::find($id) ?? throw new RuntimeException('TASK_NOT_FOUND: No task matches this ID.');

        if ($task->status !== 'running') {
            return $task;
        }

        try {
            $workspace = new Workspace($task->workspace);
            $workspace->exclusivelyForTask($task->id, function () use ($task, $workspace): void {
                $workspace->exclusively(function () use ($task): void {
                    $this->settleInterrupted($task);
                });
            });
        } catch (RuntimeException $exception) {
            if (! str_starts_with($exception->getMessage(), 'WORKSPACE_BUSY:')) {
                throw $exception;
            }
        }

        return $task->fresh();
    }

    private function settleInterrupted(Task $task): void
    {
        $task->getConnection()->transaction(function () use ($task): void {
            $stopped = Task::whereKey($task->id)->where('status', 'running')->whereNotNull('stop_requested_at')
                ->update(['status' => 'stopped']);

            if ($stopped !== 1) {
                return;
            }

            foreach ($task->runs()->where('status', 'running')->get() as $run) {
                $run->newQuery()->whereKey($run->id)->where('status', 'running')->update([
                    'status' => 'stopped',
                    'report' => [...$run->report, 'error' => 'RUN_INTERRUPTED: The original process no longer holds the workspace lock. Saved evidence remains available.'],
                ]);
            }
        });
    }
}
