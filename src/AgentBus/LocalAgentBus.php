<?php

namespace Sifrious\Molly\AgentBus;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Execution\LocalOrbProvider;
use Sifrious\Molly\Models\Orb;
use Sifrious\Molly\Models\Task;

/**
 * Local-only agent-bus claim/lease semantics. No hosted coordinator required.
 */
final class LocalAgentBus
{
    public function __construct(private RecordLifecycleEvent $lifecycle, private LocalOrbProvider $orbs) {}

    public function leaseSeconds(): int
    {
        $seconds = config('molly.agent_bus.lease_seconds', 120);
        if (! is_int($seconds) || $seconds < 30 || $seconds > 3600) {
            throw new RuntimeException('AGENT_BUS_LEASE_INVALID: Set molly.agent_bus.lease_seconds to an integer from 30 to 3600.');
        }

        return $seconds;
    }

    /**
     * The lease a live run renews at each checkpoint. It outlasts the longest
     * single bounded step, a model call (molly.timeout) or a Pest run
     * (molly.test_timeout), so a worker that is still inside one step keeps
     * its claim. A crashed worker's claim still expires after this long.
     */
    public function runLeaseSeconds(): int
    {
        $steps = array_filter([config('molly.timeout'), config('molly.test_timeout')], fn (mixed $seconds): bool => is_int($seconds) && $seconds > 0 && $seconds <= 3600);

        return max($this->leaseSeconds(), ...array_map(fn (int $seconds): int => $seconds + 30, $steps));
    }

    /**
     * Atomically claim a task for one worker. Racing workers: only one succeeds.
     * Abandoned (expired lease) running tasks are recoverable into a new claim.
     * With $orbId, the same transaction takes that Orb for the task, so a claim that
     * cannot hold the Orb changes nothing.
     */
    public function claim(string $taskId, string $workerId, bool $retry = false, ?string $idempotencyKey = null, ?string $orbId = null): Task
    {
        if (trim($workerId) === '') {
            throw new RuntimeException('WORKER_ID_INVALID: A non-empty worker identity is required to claim work.');
        }

        return DB::transaction(function () use ($taskId, $workerId, $retry, $idempotencyKey, $orbId): Task {
            $task = Task::query()->whereKey($taskId)->lockForUpdate()->first()
                ?? throw new RuntimeException('TASK_NOT_FOUND: Molly could not find that task.');

            $this->recoverIfAbandoned($task);
            $this->refuseUnclaimable($task, $retry, $idempotencyKey);
            if ($orbId !== null) {
                $this->orbs->occupy($orbId, $task);
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

    /**
     * Refuse a claim the task's state does not allow, without changing anything. StartTask
     * calls this before its workspace checks, so a running task reports TASK_NOT_PENDING.
     */
    public function refuseUnclaimable(Task $task, bool $retry = false, ?string $idempotencyKey = null): void
    {
        $this->refuseDuplicateSuccess($task, $idempotencyKey);

        $allowed = $retry ? ['failed', 'stopped'] : ['pending'];
        if (! in_array($task->status, $allowed, true)) {
            throw new RuntimeException($retry
                ? 'TASK_NOT_RETRYABLE: Only failed or stopped tasks can be retried.'
                : 'TASK_NOT_PENDING: Start a pending task or retry a failed or stopped task.');
        }

        $limit = config('molly.max_attempts', 3);
        if (! is_int($limit) || $limit < 1 || $limit > 10) {
            throw new RuntimeException('ATTEMPT_LIMIT_INVALID: Set molly.max_attempts to an integer from 1 to 10.');
        }
        if ($task->attemptsUsed() >= $limit) {
            throw new RuntimeException('ATTEMPT_LIMIT_REACHED: This task has used its allowed attempts.');
        }
        $this->refuseExhaustedRepair($task);
    }

    public function heartbeat(string $taskId, string $workerId, ?int $seconds = null): Task
    {
        $seconds ??= $this->leaseSeconds();

        return DB::transaction(function () use ($taskId, $workerId, $seconds): Task {
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
                'lease_expires_at' => $now->copy()->addSeconds($seconds),
            ]);

            return $task->refresh();
        });
    }

    /**
     * Recover running tasks whose lease expired, oldest lease first and at most $limit in one
     * call, the way recoverIfAbandoned recovers one.
     *
     * @return list<string> recovered task ids
     */
    public function recoverAbandoned(?DateTimeInterface $now = null, int $limit = 100): array
    {
        $now = $now === null ? now() : $now;
        $recovered = [];

        $candidates = Task::query()
            ->where('status', 'running')
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', $now)
            ->orderBy('lease_expires_at')
            ->orderBy('id')
            ->limit(max(0, $limit))
            ->get();

        foreach ($candidates as $task) {
            if ($this->recover($task, 'lease_expired', $now)) {
                $recovered[] = (string) $task->id;
            }
        }

        return $recovered;
    }

    /**
     * Whether Molly's default worker ID says a process on this host made the claim.
     * StartTask names a worker hostname:pid unless molly.agent_bus.worker_id is set.
     */
    public function claimedOnThisHost(Task $task): bool
    {
        $host = gethostname();

        return is_string($task->worker_id) && is_string($host) && $host !== ''
            && preg_match('/\A(.+):\d+\z/', $task->worker_id, $match) === 1 && $match[1] === $host;
    }

    /**
     * Why a running task has no live worker, or null while its claim may still be live: the
     * lease expired (`lease_expired`), or the caller holds the task's workspace lock and the
     * claim was made on this host (`worker_exited`). A live run holds that lock for its whole
     * attempt, so a free lock means the process that claimed the task has exited.
     */
    public function abandonment(Task $task, bool $taskLockHeld = false): ?string
    {
        if ($task->status !== 'running') {
            return null;
        }
        if ($this->leaseExpired($task)) {
            return 'lease_expired';
        }

        return $taskLockHeld && $this->claimedOnThisHost($task) ? 'worker_exited' : null;
    }

    /**
     * Settle a running task whose worker is gone, for the reason abandonment() gave: the task
     * becomes failed and retryable, or stopped when a stop was requested, its claim is cleared,
     * and each run its worker left running gets the same status with a RUN_ABANDONED error.
     * The update applies only while the task still holds the claim that was read, so a worker
     * that finished or renewed its lease in the meantime keeps its result.
     */
    public function recover(Task $task, string $reason, ?DateTimeInterface $now = null): bool
    {
        $now = $now === null ? now() : $now;
        $workerId = $task->worker_id;
        $leaseExpiresAt = $task->lease_expires_at?->toIso8601String();
        $status = $task->stop_requested_at === null ? 'failed' : 'stopped';
        $error = $reason === 'lease_expired'
            ? 'RUN_ABANDONED: Worker '.($workerId ?? 'unknown').' stopped renewing its lease, which expired at '.($leaseExpiresAt ?? 'an unknown time').', before this run finished. Saved evidence remains available.'
            : 'RUN_ABANDONED: Worker '.($workerId ?? 'unknown').' exited before this run finished, and no process holds the task lock. Saved evidence remains available.';

        $runIds = DB::transaction(function () use ($task, $reason, $now, $workerId, $status, $error): ?array {
            $claim = Task::query()->whereKey($task->id)->where('status', 'running')
                ->where('attempt_number', $task->attempt_number);
            $workerId === null ? $claim->whereNull('worker_id') : $claim->where('worker_id', $workerId);
            if ($reason === 'lease_expired') {
                $claim->where('lease_expires_at', '<', $now);
            }
            if ($claim->update(['status' => $status, 'worker_id' => null, 'lease_expires_at' => null, 'heartbeat_at' => null]) !== 1) {
                return null;
            }

            $runIds = [];
            $recoveredAt = Carbon::parse($now)->toIso8601String();
            foreach ($task->runs()->where('status', 'running')->get() as $run) {
                $report = [...($run->report ?? []), 'error' => $error, 'recovery' => [
                    'reason' => $reason,
                    'worker_id' => $workerId,
                    'recovered_at' => $recoveredAt,
                ]];
                if (is_array($report['execution_target'] ?? null)) {
                    $report['execution_target'] = [...$report['execution_target'], 'finished_at' => $recoveredAt, 'result' => $status];
                }
                $run->update(['status' => $status, 'report' => $report]);
                $runIds[] = (string) $run->id;
            }
            // The worker is gone, so the Orb it ran on is free for its next task.
            Orb::releaseTask($task->id);

            return $runIds;
        });
        if ($runIds === null) {
            return false;
        }

        DB::afterCommit(fn () => $this->recordRecovery($task->refresh(), $status, $reason, $workerId, $leaseExpiresAt, $runIds));

        return true;
    }

    /** Clear the task's claim and free any Orb it holds, so the Orb can take its next task. */
    public function clearClaim(Task $task): void
    {
        Task::query()->whereKey($task->id)->update([
            'worker_id' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'heartbeat_at' => null,
        ]);
        Orb::releaseTask($task->id);
    }

    public function leaseExpired(Task $task, ?DateTimeInterface $now = null): bool
    {
        if ($task->lease_expires_at === null) {
            return false;
        }

        $now = $now === null ? now() : $now;

        return $task->lease_expires_at->lt($now);
    }

    /**
     * Refuse another attempt when the latest failure has already happened
     * molly.repair.per_failure times in this scope. The total cap in
     * molly.max_attempts still applies separately.
     */
    private function refuseExhaustedRepair(Task $task): void
    {
        $budget = config('molly.repair.per_failure', 3);
        if (! is_int($budget) || $budget < 1 || $budget > 10) {
            throw new RuntimeException('REPAIR_BUDGET_INVALID: Set molly.repair.per_failure to an integer from 1 to 10.');
        }

        $repeated = $task->repeatedFailure();
        if ($repeated !== null && $repeated['failures'] >= $budget && isset($repeated['authored_test_causes'])) {
            throw new RuntimeException('REPAIR_BUDGET_EXHAUSTED: Molly wrote '.$task->test_path.' '.$repeated['failures'].' times and each time it could not run for the same cause ('
                .implode(', ', $repeated['authored_test_causes']).', fingerprint '.$repeated['digest'].'). Edit the test to fix the cause, then lock it with php artisan molly:lock-test '.$task->reference().' --approve. Run php artisan molly:task '.$task->reference().' to read the cause.');
        }
        if ($repeated !== null && $repeated['failures'] >= $budget) {
            throw new RuntimeException('REPAIR_BUDGET_EXHAUSTED: The same failure has happened '.$repeated['failures'].' times (fingerprint '.$repeated['digest'].'). Change the task scope, test, or model before another attempt.');
        }
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

    /**
     * Recover a running task whose worker is gone (see abandonment() and recover()). Pass
     * $taskLockHeld only while holding the task's workspace lock.
     * Callers snapshot the task after this, so a refused start restores the recovered state.
     * When .molly/lifecycle.jsonl already exists, a failed or stopped event with the reason
     * is appended once the recovery commits, so the displayed status matches the task row.
     */
    public function recoverIfAbandoned(Task $task, bool $taskLockHeld = false): Task
    {
        $reason = $this->abandonment($task, $taskLockHeld);
        if ($reason !== null) {
            $this->recover($task, $reason);
            $task->refresh();
        }

        return $task;
    }

    /** @param  list<string>  $runIds */
    private function recordRecovery(Task $task, string $status, string $reason, ?string $workerId, ?string $leaseExpiresAt, array $runIds): void
    {
        if (! is_file(rtrim($task->workspace, '/').'/.molly/lifecycle.jsonl')) {
            return;
        }
        $this->lifecycle->handle($task->workspace, $status === 'stopped' ? LifecycleEventType::Stopped : LifecycleEventType::Failed, $task->id, $runIds[0] ?? null, [
            'reason' => $reason,
            'worker_id' => $workerId,
            'lease_expires_at' => $leaseExpiresAt,
            'runs' => $runIds,
        ]);
    }
}
