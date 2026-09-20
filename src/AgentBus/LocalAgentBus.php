<?php

namespace Sifrious\Molly\AgentBus;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Sifrious\Molly\Models\Task;

/**
 * Local-only agent-bus claim/lease semantics. No hosted coordinator required.
 */
final class LocalAgentBus
{
    public function leaseSeconds(): int
    {
        $seconds = config('molly.agent_bus.lease_seconds', 120);
        if (! is_int($seconds) || $seconds < 30 || $seconds > 3600) {
            throw new RuntimeException('AGENT_BUS_LEASE_INVALID: Set molly.agent_bus.lease_seconds to an integer from 30 to 3600.');
        }

        return $seconds;
    }

    /**
     * Atomically claim a task for one worker. Racing workers: only one succeeds.
     * Abandoned (expired lease) running tasks are recoverable into a new claim.
     */
    public function claim(string $taskId, string $workerId, bool $retry = false, ?string $idempotencyKey = null): Task
    {
        if (trim($workerId) === '') {
            throw new RuntimeException('WORKER_ID_INVALID: A non-empty worker identity is required to claim work.');
        }

        return DB::transaction(function () use ($taskId, $workerId, $retry, $idempotencyKey): Task {
            $task = Task::query()->whereKey($taskId)->lockForUpdate()->first()
                ?? throw new RuntimeException('TASK_NOT_FOUND: Molly could not find that task.');

            $this->refuseDuplicateSuccess($task, $idempotencyKey);

            $this->recoverIfAbandoned($task);

            $allowed = $retry ? ['failed', 'stopped'] : ['pending'];
            $stateError = $retry
                ? 'TASK_NOT_RETRYABLE: Only failed or stopped tasks can be retried.'
                : 'TASK_NOT_PENDING: Start a pending task or retry a failed or stopped task.';

            if (! in_array($task->status, $allowed, true)) {
                throw new RuntimeException($stateError);
            }

            $limit = config('molly.max_attempts', 3);
            if (! is_int($limit) || $limit < 1 || $limit > 10) {
                throw new RuntimeException('ATTEMPT_LIMIT_INVALID: Set molly.max_attempts to an integer from 1 to 10.');
            }
            if ($task->runs()->count() >= $limit) {
                throw new RuntimeException('ATTEMPT_LIMIT_REACHED: This task has used its allowed attempts.');
            }

            $now = now();
            $expires = $now->copy()->addSeconds($this->leaseSeconds());
            $attempt = (int) $task->attempt_number + 1;

            $updated = Task::query()
                ->whereKey($task->id)
                ->where('status', $task->status)
                ->where('attempt_number', $task->attempt_number)
                ->update([
                    'status' => 'running',
                    'stop_requested_at' => null,
                    'worker_id' => $workerId,
                    'claimed_at' => $now,
                    'heartbeat_at' => $now,
                    'lease_expires_at' => $expires,
                    'attempt_number' => $attempt,
                    'idempotency_key' => $idempotencyKey ?? $task->idempotency_key,
                ]);

            if ($updated !== 1) {
                throw new RuntimeException('TASK_CLAIM_LOST: Another worker claimed this task first.');
            }

            return $task->refresh();
        });
    }

    public function heartbeat(string $taskId, string $workerId): Task
    {
        return DB::transaction(function () use ($taskId, $workerId): Task {
            $task = Task::query()->whereKey($taskId)->lockForUpdate()->first()
                ?? throw new RuntimeException('TASK_NOT_FOUND: Molly could not find that task.');

            if ($task->status !== 'running' || $task->worker_id !== $workerId) {
                throw new RuntimeException('TASK_LEASE_INVALID: Only the active claiming worker may heartbeat this task.');
            }
            if ($this->leaseExpired($task)) {
                throw new RuntimeException('TASK_LEASE_EXPIRED: The claim lease expired; recover before continuing.');
            }

            $now = now();
            Task::query()->whereKey($task->id)->update([
                'heartbeat_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds($this->leaseSeconds()),
            ]);

            return $task->refresh();
        });
    }

    /**
     * Deterministically mark abandoned running claims as failed so they can be retried.
     *
     * @return list<string> recovered task ids
     */
    public function recoverAbandoned(?DateTimeInterface $now = null): array
    {
        $now = $now === null ? now() : $now;
        $recovered = [];

        $candidates = Task::query()
            ->where('status', 'running')
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', $now)
            ->pluck('id');

        foreach ($candidates as $id) {
            $changed = Task::query()
                ->whereKey($id)
                ->where('status', 'running')
                ->where('lease_expires_at', '<', $now)
                ->update([
                    'status' => 'failed',
                    'worker_id' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => null,
                ]);
            if ($changed === 1) {
                $recovered[] = (string) $id;
            }
        }

        return $recovered;
    }

    public function clearClaim(Task $task): void
    {
        Task::query()->whereKey($task->id)->update([
            'worker_id' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'heartbeat_at' => null,
        ]);
    }

    public function leaseExpired(Task $task, ?DateTimeInterface $now = null): bool
    {
        if ($task->lease_expires_at === null) {
            return false;
        }

        $now = $now === null ? now() : $now;

        return $task->lease_expires_at->lt($now);
    }

    private function refuseDuplicateSuccess(Task $task, ?string $idempotencyKey): void
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return;
        }

        $prior = Task::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', 'completed')
            ->whereKeyNot($task->id)
            ->exists();

        if ($prior || ($task->idempotency_key === $idempotencyKey && $task->status === 'completed')) {
            throw new RuntimeException('COMMAND_ALREADY_SUCCEEDED: Duplicate delivery of a completed command was refused.');
        }
    }

    private function recoverIfAbandoned(Task $task): void
    {
        if ($task->status === 'running' && $this->leaseExpired($task)) {
            Task::query()
                ->whereKey($task->id)
                ->where('status', 'running')
                ->where('lease_expires_at', '<', now())
                ->update([
                    'status' => 'failed',
                    'worker_id' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => null,
                ]);
            $task->refresh();
        }
    }
}
