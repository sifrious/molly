<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\AgentBus\LocalAgentBus;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;

/**
 * Settle tasks that a killed worker or a restarted host left running, so a new worker starts
 * from consistent records. molly:worker start and restart run this before they launch the
 * queue worker.
 *
 * The sweep first recovers tasks whose lease expired. It then looks at tasks claimed by a
 * process on this host and recovers each one whose task lock it can take at once, because a
 * live run holds that lock for its whole attempt. It never waits for a lock, and each pass
 * looks at no more than $limit tasks, oldest first. LocalAgentBus::recover settles each task
 * and the runs it left running.
 */
class RecoverAbandonedTasks
{
    public const LIMIT = 50;

    public function __construct(private LocalAgentBus $bus, private RefreshProjectJournal $journal) {}

    /**
     * @return array{recovered: list<array{task_id: string, reference: string, status: string, reason: string}>, limit_reached: bool}
     */
    public function handle(int $limit = self::LIMIT): array
    {
        $expired = $this->bus->recoverAbandoned(limit: $limit);
        $reasons = array_fill_keys($expired, 'lease_expired');

        $running = Task::query()->where('status', 'running')->whereNotIn('id', array_keys($reasons))
            ->orderBy('claimed_at')->orderBy('id')->limit($limit + 1)->get();
        foreach ($running->take($limit) as $task) {
            if ($this->bus->claimedOnThisHost($task) && $this->recoverUnlocked($task)) {
                $reasons[$task->id] = 'worker_exited';
            }
        }

        $recovered = [];
        foreach ($reasons as $id => $reason) {
            $task = $this->journal->handle(Task::findOrFail($id));
            $recovered[] = ['task_id' => (string) $task->id, 'reference' => $task->reference(), 'status' => $task->status, 'reason' => $reason];
        }

        return ['recovered' => $recovered, 'limit_reached' => count($expired) >= $limit || $running->count() > $limit];
    }

    private function recoverUnlocked(Task $task): bool
    {
        try {
            return (new Workspace($task->workspace))->exclusivelyForTask($task->id, function () use ($task): bool {
                $reason = $this->bus->abandonment($task->refresh(), taskLockHeld: true);

                return $reason !== null && $this->bus->recover($task, $reason);
            });
        } catch (RuntimeException) {
            // WORKSPACE_BUSY means a live process holds the lock. A workspace that no longer
            // exists cannot be checked, so the task waits for its lease like any other.
            return false;
        }
    }
}
