<?php

namespace Sifrious\Molly\Actions;

use Closure;
use RuntimeException;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;
use Throwable;

class StartTask
{
    public function __construct(private RunTask $runTask) {}

    public function handle(string $id, ?Closure $progress = null, bool $retry = false): Run
    {
        $task = Task::find($id);
        if ($task === null) {
            throw new RuntimeException('TASK_NOT_FOUND: Molly could not find that task.');
        }

        return (new Workspace($task->workspace))->exclusivelyForTask($id, function () use ($id, $progress, $retry): Run {
            $task = Task::findOrFail($id);
            $this->claim($task, $retry);

            return $this->execute($task, $progress, $retry);
        });
    }

    private function claim(Task $task, bool $retry): void
    {
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
        if (Task::whereKey($task->id)->whereIn('status', $allowed)->update(['status' => 'running', 'stop_requested_at' => null]) !== 1) {
            throw new RuntimeException($stateError);
        }
    }

    private function execute(Task $task, ?Closure $progress, bool $retry): Run
    {
        $id = $task->id;
        try {
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

            return $run->fresh();
        } catch (Throwable $exception) {
            Task::whereKey($id)->where('status', 'running')->whereNull('stop_requested_at')->update(['status' => 'failed']);
            Task::whereKey($id)->where('status', 'running')->whereNotNull('stop_requested_at')->update(['status' => 'stopped']);
            throw $exception;
        }
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
