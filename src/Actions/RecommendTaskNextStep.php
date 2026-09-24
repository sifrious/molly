<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;
use Throwable;

class RecommendTaskNextStep
{
    public function __construct(private EvaluateWithTypeSafe $evaluator) {}

    /** @return array<string, mixed> */
    public function handle(string $reference): array
    {
        $task = app(ShowTask::class)->handle($reference)
            ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        $run = $task->runs->last();
        $advice = $this->deterministic($task, $run);
        $evidence = $run === null ? null : $this->evidence($task, $run);
        $eligible = $task->status === 'failed' && $run?->status === 'failed' && $advice['retry_allowed'];
        if ($eligible && ! $this->hasEvidence($evidence)) {
            $advice['reason'] .= ' No usable verification or Tarpit evidence was saved, so Molly did not request a model recommendation.';
            $advice['provider']['reason'] = 'missing_evidence';
        } elseif ($eligible) {
            $started = hrtime(true);
            try {
                $evaluation = $this->evaluator->handle($evidence);
            } catch (Throwable) {
                $evaluation = ['status' => 'unavailable', 'reason' => 'provider_unavailable'];
            }
            $evaluation['duration_ms'] = (int) round((hrtime(true) - $started) / 1_000_000);
            $current = app(ShowTask::class)->handle($task->id)
                ?? throw new RuntimeException('TASK_NOT_FOUND: The task was removed while Molly requested advice.');
            $latest = $current->runs->last();
            $advice = $this->deterministic($current, $latest);
            if ($current->status !== $task->status || $current->runs->count() !== $task->runs->count() || $latest?->id !== $run->id || $this->reportWithoutAdvice($latest) !== $this->reportWithoutAdvice($run)) {
                $advice['status'] = 'fallback';
                $advice['fallback'] = true;
                $advice['reason'] .= ' Saved evidence changed during the request, so Molly discarded the Jev recommendation.';
                $advice['provider'] = $this->provider($evaluation);
                $advice['provider']['reason'] = 'evidence_changed';
            } elseif ($advice['retry_allowed']) {
                $advice = $this->applyEvaluation($advice, $evaluation, $current);
            }
            $run = $latest;
        }
        if ($run !== null && in_array($run->status, ['completed', 'failed', 'stopped'], true)) {
            $advice['persisted'] = $this->save($run, $advice);
            if (! $advice['persisted']) {
                $current = app(ShowTask::class)->handle($task->id)
                    ?? throw new RuntimeException('TASK_NOT_FOUND: The task was removed before Molly saved advice.');
                $advice = $this->deterministic($current, $current->runs->last());
                $advice['status'] = 'fallback';
                $advice['fallback'] = true;
                $advice['provider']['reason'] = 'evidence_changed';
                $advice['reason'] .= ' Saved evidence changed before advice could be stored. Request advice again to review the current evidence.';
            }
        }

        return $advice;
    }

    /** @return array<string, mixed> */
    private function deterministic(Task $task, ?Run $run): array
    {
        $limit = config('molly.max_attempts', 3);
        $validLimit = is_int($limit) && $limit >= 1 && $limit <= 10;
        $count = $task->attemptsUsed();
        $retryAllowed = $validLimit && in_array($task->status, ['failed', 'stopped'], true) && $count < $limit;
        [$action, $reason] = match (true) {
            ! $validLimit => ['inspect', 'The attempt limit is invalid. Set molly.max_attempts to an integer from 1 to 10 before starting another attempt.'],
            $task->status === 'running' => ['wait', 'This task is marked running. Inspect its current evidence before requesting another action.'],
            $task->status === 'completed' => ['done', 'This task is completed. Review the saved evidence and changed files before committing.'],
            $count >= $limit => ['stop', 'This task has used all '.$limit.' allowed attempts. Another start or retry is blocked.'],
            $task->status === 'pending' => ['start', 'This task is pending and has an attempt available. Start it when you are ready.'],
            $task->status === 'stopped' => ['inspect', 'This task stopped. Inspect any applied changes before choosing whether to retry.'],
            $task->status === 'failed' => ['inspect', $run === null ? 'This task failed without a saved attempt. Inspect the task before choosing whether to retry.' : 'This task failed. Inspect its test and Tarpit evidence before choosing whether to retry.'],
            default => ['inspect', 'This task has an unrecognized status. Inspect the saved record before continuing.'],
        };

        return [
            'task_id' => $task->id, 'run_id' => $run?->id, 'task_reference' => $task->reference(),
            'status' => 'deterministic', 'next_action' => $action, 'reason' => $reason,
            'retry_allowed' => $retryAllowed, 'command' => $this->command($action, $task, $run),
            'observed' => ['task_status' => $task->status, 'attempt_count' => $count, 'max_attempts' => $validLimit ? $limit : null],
            'evidence_refs' => ['task' => 'molly:task '.$task->id, 'run' => $run === null ? null : 'molly:show '.$run->id, 'verification' => $run === null ? null : 'report.verification', 'review' => $run === null ? null : 'report.review'],
            'provider' => $this->provider(['status' => 'not_requested', 'reason' => 'deterministic_guidance']),
            'confidence' => null, 'fallback' => false, 'persisted' => false, 'recorded_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $advice
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    private function applyEvaluation(array $advice, array $evaluation, Task $task): array
    {
        $advice['provider'] = $this->provider($evaluation);
        $advice['confidence'] = $this->probability($evaluation['confidence'] ?? null);
        $choice = $evaluation['next_action'] ?? null;
        if (($evaluation['status'] ?? null) !== 'evaluated' || ! in_array($choice, ['continue', 'retry', 'stop', 'needs_review'], true)) {
            $advice['status'] = 'fallback';
            $advice['fallback'] = true;
            $advice['reason'] .= ' '.$this->fallbackReason($evaluation['reason'] ?? null);

            return $advice;
        }

        $advice['status'] = 'evaluated';
        $advice['next_action'] = match ($choice) {
            'retry' => 'retry',
            'stop' => 'stop',
            default => 'inspect',
        };
        $advice['reason'] = match ($choice) {
            'retry' => 'Jev recommends another bounded attempt. Retry is allowed, but the recorded failure remains until a new attempt passes its required checks.',
            'stop' => 'Jev recommends ending this approach. Another retry is still allowed by the attempt limit, but this advice recommends against it. No task state changed.',
            'continue' => 'Jev returned continue, but this task failed. Inspect the recorded checks before choosing whether to retry. The recommendation does not establish success.',
            default => 'Jev recommends human review. Inspect the recorded evidence before choosing whether to retry.',
        };
        $advice['command'] = $this->command($advice['next_action'], $task, $task->runs->last());

        return $advice;
    }

    private function command(string $action, Task $task, ?Run $run): ?string
    {
        return match ($action) {
            'start' => 'php artisan molly:start '.$task->reference(),
            'retry' => 'php artisan molly:retry '.$task->reference(),
            'stop' => null,
            default => $run === null ? 'php artisan molly:task '.$task->reference() : 'php artisan molly:show '.$run->id,
        };
    }

    private function fallbackReason(mixed $reason): string
    {
        return match ($reason) {
            'disabled', 'jev_disabled' => 'Jev is disabled, so this is deterministic guidance.',
            'capability_missing' => 'Jev is enabled, but the installed Laravel AI does not provide classification, so this is deterministic guidance.',
            'invalid_config' => 'Jev is not configured for a request. No model recommendation is available.',
            'invalid_evidence' => 'The saved evidence could not be sent to Jev. Molly kept the deterministic guidance.',
            'provider_error' => 'The Jev provider request failed. Molly kept the deterministic guidance.',
            'low_confidence' => 'Jev confidence was below the configured threshold. Molly kept the deterministic guidance.',
            default => 'Jev did not return a usable recommendation. Molly kept the deterministic guidance.',
        };
    }

    /**
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    private function provider(array $evaluation): array
    {
        $answers = [];
        $answer = $evaluation['answers']['next_action'] ?? [];
        if (in_array($answer['choice'] ?? null, ['continue', 'retry', 'stop', 'needs_review'], true)) {
            $probabilities = [];
            foreach (['continue', 'retry', 'stop', 'needs_review'] as $choice) {
                $probabilities[$choice] = $this->probability($answer['probabilities'][$choice] ?? null);
            }
            $answers['next_action'] = ['type' => 'choice', 'choice' => $answer['choice'], 'confidence' => $this->probability($answer['confidence'] ?? null), 'probabilities' => $probabilities];
        }

        return [
            'name' => 'typesafe', 'status' => $this->boundedText($evaluation['status'] ?? 'unavailable', 32),
            'reason' => $this->boundedText($evaluation['reason'] ?? 'provider_unavailable', 64),
            'model' => isset($evaluation['model']) ? $this->boundedText($evaluation['model'], 128) : null,
            'threshold' => $this->probability(config('molly.jev.confidence_threshold')),
            'answers' => $answers, 'duration_ms' => is_int($evaluation['duration_ms'] ?? null) ? $evaluation['duration_ms'] : null,
        ];
    }

    /** @param array<string, mixed>|null $evidence */
    private function hasEvidence(?array $evidence): bool
    {
        return in_array($evidence['verification']['status'] ?? null, ['passed', 'failed', 'error', 'timeout'], true)
            || ! empty($evidence['review']['checks']) || ! empty($evidence['review']['findings']);
    }

    /** @return array<string, mixed> */
    private function evidence(Task $task, Run $run): array
    {
        $report = $run->report ?? [];
        $evidence = ['prompt' => $this->boundedText($task->prompt, 2048), 'verification' => [], 'review' => ['checks' => [], 'findings' => []]];
        if (is_string($report['verification']['status'] ?? null)) {
            $evidence['verification']['status'] = $this->boundedText($report['verification']['status'], 32);
        }
        foreach (['tests', 'assertions', 'failures', 'errors', 'skipped'] as $key) {
            if (is_int($report['verification'][$key] ?? null) && $report['verification'][$key] >= 0) {
                $evidence['verification'][$key] = $report['verification'][$key];
            }
        }
        foreach (range('A', 'G') as $code) {
            if (is_string($report['review']['checks'][$code]['status'] ?? null)) {
                $evidence['review']['checks'][$code] = ['status' => $this->boundedText($report['review']['checks'][$code]['status'], 32)];
            }
        }
        $findings = collect($report['review']['findings'] ?? [])->filter(fn (mixed $finding): bool => is_array($finding) && in_array($finding['path'] ?? null, $task->paths ?? [], true))
            ->sortByDesc(fn (array $finding): bool => ($finding['severity'] ?? null) === 'blocking')->take(3);
        foreach ($findings as $finding) {
            $selected = [];
            foreach (['code' => 8, 'classification' => 24, 'severity' => 16, 'path' => 192, 'problem' => 384, 'recommendation' => 384] as $key => $limit) {
                if (is_string($finding[$key] ?? null)) {
                    $selected[$key] = $this->boundedText($finding[$key], $limit);
                }
            }
            if (is_int($finding['line'] ?? null) && $finding['line'] > 0) {
                $selected['line'] = $finding['line'];
            }
            $evidence['review']['findings'][] = $selected;
        }

        return $evidence;
    }

    private function boundedText(mixed $value, int $bytes): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = mb_strcut(mb_convert_encoding($value, 'UTF-8', 'UTF-8'), 0, $bytes, 'UTF-8');
        while (strlen(json_encode($value, JSON_THROW_ON_ERROR)) > $bytes) {
            $value = mb_strcut($value, 0, intdiv(strlen($value), 2), 'UTF-8');
        }

        return $value;
    }

    private function probability(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1 ? (float) $value : null;
    }

    /** @return array<string, mixed> */
    private function reportWithoutAdvice(?Run $run): array
    {
        $report = $run?->report ?? [];
        unset($report['advice']);

        return $report;
    }

    /** @param array<string, mixed> $advice */
    private function save(Run $run, array $advice): bool
    {
        return $run->getConnection()->transaction(function () use ($run, $advice): bool {
            $task = Task::whereKey($advice['task_id'])->lockForUpdate()->firstOrFail();
            $saved = Run::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($task->status !== $advice['observed']['task_status'] || $task->attemptsUsed() !== $advice['observed']['attempt_count']
                || $task->reference() !== $advice['task_reference'] || $saved->status !== $run->status || $this->reportWithoutAdvice($saved) !== $this->reportWithoutAdvice($run)) {
                return false;
            }
            $saved->timestamps = false;
            $saved->update(['report' => [...($saved->report ?? []), 'advice' => [...$advice, 'persisted' => true]]]);

            return true;
        });
    }
}
