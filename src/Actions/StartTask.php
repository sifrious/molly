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

        return (new Workspace($task->workspace))->exclusivelyForTask($id, function () use ($id, $progress, $retry): Run {
            $task = Task::findOrFail($id);
            $workerId = $this->workerId();
            $idempotencyKey = $task->id.':'.($retry ? 'retry' : 'start');
            $this->bus->claim($task->id, $workerId, $retry, $idempotencyKey);
            $this->journal->handle($task->refresh());

            return $this->execute($task->refresh(), $progress, $retry, $workerId);
        });
    }

    private function execute(Task $task, ?Closure $progress, bool $retry, string $workerId): Run
    {
        $id = $task->id;
        try {
            $this->bus->heartbeat($id, $workerId);

            if ($retry) {
                $this->baseline->handle($task);
                $this->lifecycle->handle($task->workspace, LifecycleEventType::RetryScheduled, $task->id);
            }
            $this->lifecycle->handle($task->workspace, LifecycleEventType::WorkspacePrepared, $task->id, payload: [
                'workspace' => $task->workspace,
                'created_worktree' => false,
            ]);
            $previousAttempt = $retry ? $this->previousAttempt($task) : null;
            $run = $this->runTask->handle(
                $task->prompt, $task->workspace, $task->paths, $task->test_path, $progress,
                ...[
                    'taskId' => $id,
                    'shouldStop' => fn (): bool => Task::whereKey($id)->whereNotNull('stop_requested_at')->exists(),
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
            if ($finished->status === 'completed') {
                $this->lifecycle->handle($task->workspace, LifecycleEventType::VerificationFinished, $task->id, $finished->id);
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
                );
            }

            return $finished;
        } catch (Throwable $exception) {
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
            $this->bus->clearClaim($task->refresh());
            $this->journal->handle($task->refresh());
        }
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
        $evidence = ['run_id' => $run->id, 'status' => $run->status, 'verification' => [], 'review_findings' => []];
        foreach (['status' => 32, 'reason' => 128, 'output' => 2048] as $key => $limit) {
            if (is_string($report['verification'][$key] ?? null)) {
                $evidence['verification'][$key] = $this->boundedText($report['verification'][$key], $limit);
            }
        }
        foreach (['tests', 'assertions', 'failures', 'errors', 'skipped'] as $key) {
            if (is_int($report['verification'][$key] ?? null)) {
                $evidence['verification'][$key] = $report['verification'][$key];
            }
        }
        $findings = collect($report['review']['findings'] ?? [])
            ->sortByDesc(fn (array $finding): bool => ($finding['severity'] ?? null) === 'blocking');
        foreach ($findings as $finding) {
            if (! in_array($finding['path'] ?? null, $task->paths, true)) {
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
            $evidence['review_findings'][] = $summary;
            if (count($evidence['review_findings']) === 3) {
                break;
            }
        }
        if (is_string($report['error'] ?? null)) {
            $evidence['error'] = $this->boundedText($report['error'], 512);
        }

        $output = is_string($report['verification']['output'] ?? null)
            ? $report['verification']['output']
            : null;
        foreach ($this->pestAssertionHints->handle($output) as $hint) {
            $summary = [];
            foreach (['pattern' => 64, 'hint' => 512] as $key => $limit) {
                if (is_string($hint[$key] ?? null)) {
                    $summary[$key] = $this->boundedText($hint[$key], $limit);
                }
            }
            if ($summary !== []) {
                $evidence['assertion_hints'][] = $summary;
            }
        }

        return $evidence;
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
