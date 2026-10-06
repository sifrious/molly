<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Seams\SeamError;
use Sifrious\Molly\Seams\SeamSteps;
use Sifrious\Molly\Seams\StepResult;
use Sifrious\Molly\Verification\VerificationState;
use Sifrious\Molly\Workspace;
use Sifrious\Molly\Workspace\Directory;

final class ExecuteSeamStep
{
    public function __construct(private GuardSeamRevision $guard, private SeamSteps $steps,
        private RecordSeamEvent $events, private RecordSeamAttempt $evidence, private DecideRunCompletion $completion) {}

    public function handle(string $runId): Run
    {
        $attempt = Run::findOrFail($runId);
        $revision = SeamRevision::findOrFail($attempt->report['seam']['revision_id']);
        $workspace = new Workspace($revision->snapshot['workspace']);

        return $workspace->exclusively(function () use ($revision, $attempt, $workspace): Run {
            $revision->refresh();
            $attempt->refresh();
            if (in_array($attempt->status, ['completed', 'failed', 'stopped'], true)) {
                if ($revision->active_run_id === $attempt->id && isset($attempt->report['step_result'])) {
                    $this->finish($revision, $attempt, [...$attempt->report['step_result'], 'attempt_id' => $attempt->id, 'artifacts' => $attempt->report['seam']['artifacts']]);
                }

                return $attempt;
            }
            $directory = Directory::molly($workspace->path, 'receipts/'.$attempt->id.'/'.($attempt->status === 'running' ? 'interrupted-'.bin2hex(random_bytes(8)) : 'seam'));
            Directory::ensure($directory, 0700);
            $started = hrtime(true);
            $timeout = config('molly.test_timeout');
            $step = $revision->snapshot['pack']['plan']['steps'][$revision->cursor] ?? null;
            try {
                if ($attempt->status === 'running') {
                    // The previous worker released its workspace lock without recording a terminal result.
                    throw new SeamError('PROCESS_CANCELLED', 'The previous attempt was interrupted. Start a linked retry with a new request key.', $attempt->id);
                }
                $attempt->update(['status' => 'running']);
                $this->events->handle($revision, $attempt, LifecycleEventType::CommandStarted);
                if ($revision->status === 'cancelling') {
                    throw new SeamError('PROCESS_CANCELLED', 'Cancellation was requested before this step ran.', $attempt->id);
                }
                if ($revision->active_run_id !== $attempt->id || ($step['id'] ?? null) !== $attempt->report['seam']['step_id']) {
                    throw new SeamError('PLAN_STEP_NOT_ALLOWED', 'The queued step no longer matches the saved plan cursor.', $attempt->id);
                }
                $before = $this->guard->handle($revision);
                config(['molly.test_timeout' => min((int) $timeout, max(1, (int) config('molly-seams.step_timeout', 300) / 3))]);
                $result = $this->steps->handler($step['handler'])->handle($revision, $attempt, $step['arguments'], $directory);
                $after = $this->guard->handle($revision);
                if ($before !== $after) {
                    throw new SeamError('STALE_EVIDENCE', 'The application changed during verification. Retry against a stable candidate.', $revision->id);
                }
                $revision->refresh();
                if ($revision->status === 'cancelling') {
                    throw new SeamError('PROCESS_CANCELLED', 'Cancellation was requested during the bounded check. Its results cannot advance the plan.', $attempt->id);
                }
            } catch (\Throwable $exception) {
                $error = $exception instanceof SeamError ? $exception : new SeamError('ENVIRONMENT_UNAVAILABLE', $exception->getMessage(), $attempt->id);
                $result = new StepResult(VerificationState::NotRun, ['step_id' => $attempt->report['seam']['step_id']], [], $error);
            } finally {
                config(['molly.test_timeout' => $timeout]);
            }
            $record = $this->evidence->handle($revision, $attempt, $result, $directory, (hrtime(true) - $started) / 1_000_000);
            $this->finish($revision, $attempt, $record);

            return $attempt->fresh();
        });
    }

    private function finish(SeamRevision $revision, Run $attempt, array $record): void
    {
        $revision->refresh();
        if ($revision->active_run_id !== $attempt->id) {
            return;
        }
        $results = [...$revision->results, $attempt->report['seam']['step_id'] => $record];
        $passed = $record['state'] === VerificationState::Pass->value;
        $decision = $this->completion->forSeam($revision->snapshot['policies'], $results);
        $finished = $revision->cursor + ($passed ? 1 : 0) === count($revision->snapshot['pack']['plan']['steps']);
        $revision->update(['results' => $results, 'active_run_id' => null, 'cursor' => $revision->cursor + ($passed ? 1 : 0),
            'status' => $revision->status === 'cancelling' ? 'cancelled' : ($decision['completed'] && $finished ? 'completed' : ($passed ? 'ready' : 'blocked'))]);
    }
}
