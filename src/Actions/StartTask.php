<?php

namespace Sifrious\Molly\Actions;

use Closure;
use RuntimeException;
use Sifrious\Molly\AgentBus\LocalAgentBus;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Verification\PestAssertionHints;
use Sifrious\Molly\Workspace;
use Throwable;

class StartTask
{
    /** Columns a claim changes. A refused start writes these back exactly. */
    private const CLAIM_COLUMNS = ['status', 'attempt_number', 'idempotency_key', 'stop_requested_at', 'worker_id', 'claimed_at', 'heartbeat_at', 'lease_expires_at'];

    public function __construct(
        private RunTask $runTask,
        private RefreshProjectJournal $journal,
        private RestoreTaskBaseline $baseline,
        private RecordLifecycleEvent $lifecycle,
        private LocalAgentBus $bus,
        private PestAssertionHints $pestAssertionHints,
    ) {}

    public function handle(string $id, ?Closure $progress = null, bool $retry = false): Run
    {
        $task = Task::findByReference($id);
        if ($task === null) {
            throw new RuntimeException('TASK_NOT_FOUND: Molly could not find that task.');
        }
        $id = $task->id;
        $workspace = new Workspace($task->workspace);
        $idempotencyKey = $task->id.':'.($retry ? 'retry' : 'start');

        // Before taking the task lock, which creates .molly/, check what needs no write: the task's
        // state, its inputs, Git, a committed checkout, and the sandbox, in that order. A task that
        // is still running goes straight to the lock, which reports WORKSPACE_BUSY while its run
        // holds it and TASK_NOT_PENDING otherwise.
        $task = $this->bus->recoverIfAbandoned($task);
        if ($task->status !== 'running') {
            $this->bus->refuseUnclaimable($task, $retry, $idempotencyKey);
            try {
                $this->runTask->refuseUnready($task->prompt, $workspace, $task->paths, $task->test_path, $task);
            } catch (RuntimeException $exception) {
                $this->recordRefusal($task, $exception->getMessage(), $retry);
                throw $exception;
            }
        }

        return $workspace->exclusivelyForTask($id, function () use ($id, $progress, $retry, $idempotencyKey): Run {
            $task = $this->bus->recoverIfAbandoned(Task::findOrFail($id));
            if (($blocked = $task->redBaselineError()) !== null) {
                $this->recordRefusal($task, $blocked, $retry);
                throw new RuntimeException($blocked);
            }
            $workerId = $this->workerId();
            // Snapshot after recovering an abandoned claim, so a refusal restores the recovered state.
            $unclaimed = $task->only(self::CLAIM_COLUMNS);
            $this->bus->claim($task->id, $workerId, $retry, $idempotencyKey);
            $this->journal->handle($task->refresh());

            return $this->execute($task->refresh(), $progress, $retry, $workerId, $unclaimed);
        });
    }

    /** @param  array<string, mixed>  $unclaimed  The task's claim columns before this claim. */
    private function execute(Task $task, ?Closure $progress, bool $retry, string $workerId, array $unclaimed): Run
    {
        $id = $task->id;
        $runCreated = false;
        $released = false;
        try {
            $this->bus->heartbeat($id, $workerId, $this->bus->runLeaseSeconds());

            if ($retry) {
                $this->baseline->handle($task);
            }
            $previousAttempt = $retry ? $this->previousAttempt($task) : null;
            // Record preparation only once RunTask has passed its preconditions and saved a run.
            $prepared = function () use ($task, $retry, &$runCreated): void {
                $runCreated = true;
                if ($retry) {
                    $this->lifecycle->handle($task->workspace, LifecycleEventType::RetryScheduled, $task->id);
                }
                $this->lifecycle->handle($task->workspace, LifecycleEventType::WorkspacePrepared, $task->id, payload: [
                    'workspace' => $task->workspace,
                    'created_worktree' => false,
                ]);
            };
            $run = $this->runTask->handle(
                $task->prompt, $task->workspace, $task->paths, $task->test_path, $progress,
                ...[
                    'taskId' => $id,
                    'shouldStop' => fn (): bool => Task::whereKey($id)->whereNotNull('stop_requested_at')->exists(),
                    'heartbeat' => fn () => $this->bus->heartbeat($id, $workerId, $this->bus->runLeaseSeconds()),
                    'prepared' => $prepared,
                    ...($previousAttempt === null ? [] : ['previousAttempt' => $previousAttempt]),
                ],
            );
            $finished = Task::whereKey($id)->where('status', 'running')->whereNull('stop_requested_at')
                ->update(['status' => $run->status]);
            if ($finished === 0 && $task->fresh()->stop_requested_at !== null) {
                $run->update(['status' => 'stopped', 'report' => [...$run->report, 'stop_reason' => 'The task received a stop request.']]);
                Task::whereKey($id)->where('status', 'running')->update(['status' => 'stopped']);
            }

            $finished = $run->fresh();
            $authored = $this->authoredTestSummary($finished->report ?? []);
            if ($finished->status === 'completed') {
                $this->lifecycle->handle($task->workspace, LifecycleEventType::VerificationFinished, $task->id, $finished->id, $authored);
                $this->lifecycle->handle($task->workspace, LifecycleEventType::ApprovalRequested, $task->id, $finished->id, [
                    'before_pull_request' => true,
                    'before_merge' => true,
                ]);
            } else {
                $this->lifecycle->handle(
                    $task->workspace,
                    $finished->status === 'stopped' ? LifecycleEventType::Stopped : LifecycleEventType::Failed,
                    $task->id,
                    $finished->id,
                    $authored,
                );
            }

            return $finished;
        } catch (Throwable $exception) {
            // A start refused before any run was saved, such as WORKSPACE_BUSY or BASELINE_MISSING,
            // writes back the task's claim columns exactly as they were instead of failing it.
            $released = ! $runCreated && Task::whereKey($id)->where('status', 'running')->where('worker_id', $workerId)
                ->whereNull('stop_requested_at')->update($unclaimed) === 1;
            if ($released) {
                $this->recordRefusal($task, $exception->getMessage(), $retry);
                throw $exception;
            }
            Task::whereKey($id)->where('status', 'running')->whereNull('stop_requested_at')->update(['status' => 'failed']);
            Task::whereKey($id)->where('status', 'running')->whereNotNull('stop_requested_at')->update(['status' => 'stopped']);
            $current = $task->fresh();
            $this->lifecycle->handle(
                $task->workspace,
                $current->status === 'stopped' ? LifecycleEventType::Stopped : LifecycleEventType::Failed,
                $task->id,
            );
            throw $exception;
        } finally {
            if (! $released) {
                $this->bus->clearClaim($task->refresh());
            }
            $this->journal->handle($task->refresh());
        }
    }

    /**
     * Append start_refused to an existing .molly/lifecycle.jsonl. A refusal never creates
     * .molly/, so a workspace without a lifecycle log only gets the error.
     */
    private function recordRefusal(Task $task, string $message, bool $retry): void
    {
        if (! is_file(rtrim($task->workspace, '/').'/.molly/lifecycle.jsonl')) {
            return;
        }
        $this->lifecycle->handle($task->workspace, LifecycleEventType::StartRefused, $task->id, payload: [
            'code' => preg_match('/\A([A-Z][A-Z0-9_]+):/', $message, $match) === 1 ? $match[1] : 'COMMAND_FAILED',
            'message' => $this->boundedText($message, 512),
            'retry' => $retry,
        ]);
    }

    /**
     * The authored test check of a test-authoring run for its lifecycle event:
     * the classification, reason, and each cause with its affected tests.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function authoredTestSummary(array $report): array
    {
        $check = $report['authored_test'] ?? null;
        if (! is_array($check) || ! is_string($check['classification'] ?? null)) {
            return [];
        }

        return ['authored_test' => [
            'classification' => $check['classification'],
            'reason' => is_string($check['reason'] ?? null) ? $this->boundedText($check['reason'], 128) : null,
            'test_broken' => ($check['test_broken'] ?? false) === true,
            'causes' => array_values(array_map(fn (array $cause): array => [
                'cause' => (string) ($cause['cause'] ?? ''),
                'tests' => array_values(array_map(fn (mixed $name): string => $this->boundedText((string) $name, 128), array_slice($cause['tests'] ?? [], 0, 20))),
            ], array_filter($check['causes'] ?? [], is_array(...)))),
        ]];
    }

    private function workerId(): string
    {
        $configured = config('molly.agent_bus.worker_id');
        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return gethostname().':'.getmypid();
    }

    /** @return array<string, mixed>|null */
    private function previousAttempt(Task $task): ?array
    {
        $run = $task->runs()->reorder()->latest()->orderByDesc('id')->first();
        if ($run === null) {
            return null;
        }

        $report = $run->report ?? [];
        $evidence = [
            'run_id' => $run->id,
            'status' => $run->status,
            'verification' => $this->boundedVerification($report['verification'] ?? []),
            'review_findings' => $this->boundedReviewFindings($report['review']['findings'] ?? [], $task->paths),
        ];
        if (is_string($report['error'] ?? null)) {
            $evidence['error'] = $this->boundedText($report['error'], 512);
        }
        $output = is_string($report['verification']['output'] ?? null)
            ? $report['verification']['output']
            : null;
        $authored = $this->authoredTestGuidance($report['authored_test'] ?? null);
        if ($authored !== null) {
            $evidence['authored_test'] = $authored;
        }
        $hints = $this->boundedAssertionHints($output);
        if ($hints !== []) {
            $evidence['assertion_hints'] = $hints;
        }

        return $evidence;
    }

    /**
     * Molly's guidance for rewriting an authored test that could not run: the
     * classification and, per cause, the guidance and up to five affected tests.
     *
     * @return array<string, mixed>|null
     */
    private function authoredTestGuidance(mixed $check): ?array
    {
        if (! is_array($check) || ($check['classification'] ?? null) !== 'bootstrap_error') {
            return null;
        }
        $causes = [];
        foreach (array_slice(array_filter($check['causes'] ?? [], is_array(...)), 0, 4) as $cause) {
            if (! is_string($cause['guidance'] ?? null)) {
                continue;
            }
            $causes[] = [
                'cause' => $this->boundedText((string) ($cause['cause'] ?? ''), 64),
                'guidance' => $this->boundedText($cause['guidance'], 512),
                'tests' => array_map(fn (mixed $name): string => $this->boundedText((string) $name, 128), array_slice($cause['tests'] ?? [], 0, 5)),
            ];
        }

        return $causes === [] ? null : ['classification' => 'bootstrap_error', 'causes' => $causes];
    }

    /** @param  array<string, mixed>  $verification
     *  @return array<string, mixed> */
    private function boundedVerification(array $verification): array
    {
        $bounded = [];
        foreach (['status' => 32, 'reason' => 128, 'output' => 2048] as $key => $limit) {
            if (is_string($verification[$key] ?? null)) {
                $bounded[$key] = $this->boundedText($verification[$key], $limit);
            }
        }
        foreach (['tests', 'assertions', 'failures', 'errors', 'skipped'] as $key) {
            if (is_int($verification[$key] ?? null)) {
                $bounded[$key] = $verification[$key];
            }
        }

        return $bounded;
    }

    /**
     * @param  list<mixed>  $findings
     * @param  list<string>  $paths
     * @return list<array<string, mixed>>
     */
    private function boundedReviewFindings(array $findings, array $paths): array
    {
        $selected = [];
        $ordered = collect($findings)
            ->filter(fn (mixed $finding): bool => is_array($finding))
            ->sortByDesc(fn (array $finding): bool => ($finding['severity'] ?? null) === 'blocking');
        foreach ($ordered as $finding) {
            if (! in_array($finding['path'] ?? null, $paths, true)) {
                continue;
            }
            $summary = [];
            foreach (['code' => 8, 'classification' => 24, 'severity' => 16, 'path' => 192, 'problem' => 384, 'recommendation' => 384] as $key => $limit) {
                if (is_string($finding[$key] ?? null)) {
                    $summary[$key] = $this->boundedText($finding[$key], $limit);
                }
            }
            if (is_int($finding['line'] ?? null)) {
                $summary['line'] = $finding['line'];
            }
            $selected[] = $summary;
            if (count($selected) === 3) {
                break;
            }
        }

        return $selected;
    }

    /** @return list<array<string, string>> */
    private function boundedAssertionHints(?string $output): array
    {
        $hints = [];
        foreach ($this->pestAssertionHints->handle($output) as $hint) {
            $summary = [];
            foreach (['pattern' => 64, 'hint' => 512] as $key => $limit) {
                if (is_string($hint[$key] ?? null)) {
                    $summary[$key] = $this->boundedText($hint[$key], $limit);
                }
            }
            if ($summary !== []) {
                $hints[] = $summary;
            }
        }

        return $hints;
    }

    private function boundedText(string $value, int $bytes): string
    {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $value = mb_strcut($value, 0, $bytes, 'UTF-8');
        while (strlen(json_encode($value, JSON_THROW_ON_ERROR)) > $bytes) {
            $value = mb_strcut($value, 0, intdiv(strlen($value), 2), 'UTF-8');
        }

        return $value;
    }
}
