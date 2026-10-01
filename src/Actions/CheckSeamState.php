<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Seams\SeamError;
use Sifrious\Molly\Seams\SeamSteps;
use Sifrious\Molly\Seams\StepHandler;
use Sifrious\Molly\Seams\StepResult;
use Sifrious\Molly\Verification\VerificationState;

final class CheckSeamState implements StepHandler
{
    public function __construct(private GuardSeamRevision $guard) {}

    public function argumentSchema(): array
    {
        return SeamSteps::emptyArguments();
    }

    public function handle(SeamRevision $revision, Run $attempt, array $arguments, string $evidenceDirectory): StepResult
    {
        $candidate = $this->guard->handle($revision);
        $step = $attempt->report['seam']['step_id'];
        $task = $revision->task()->firstOrFail();
        if ($step === 'lock') {
            $locked = ! $task->allow_test_edits && ($task->source['test_lock']['approved_by'] ?? null) === 'human'
                && $task->test_digest === $revision->snapshot['preview']['sha256'];

            return new StepResult($locked ? VerificationState::Pass : VerificationState::ReviewRequired,
                ['approval' => 'human', 'test_digest' => $revision->snapshot['preview']['sha256']], ['locked' => $locked],
                $locked ? null : new SeamError('HUMAN_APPROVAL_REQUIRED', 'A person must review and lock this test.', $task->test_path,
                    repair: 'Run php artisan molly:lock-test '.$task->id.' --approve, then retry this step.'));
        }
        if ($step === 'handoff') {
            $ack = $revision->results['handoff_acknowledgement'] ?? null;
            $passed = is_array($ack) && ($ack['candidate_digest'] ?? null) === $candidate['tree_digest'];

            return new StepResult($passed ? VerificationState::Pass : VerificationState::ReviewRequired,
                ['recipient_acknowledgement' => true], ['candidate' => $candidate, 'acknowledgement' => $ack],
                $passed ? null : new SeamError('HANDOFF_INVALID', 'The receiving agent must verify and acknowledge this revision and its evidence.', $revision->id));
        }
        if ($step === 'cleanup') {
            $sensitivity = $revision->results['sensitivity']['observed'] ?? [];
            if (($sensitivity['cleaned_up'] ?? false) !== true) {
                return new StepResult(VerificationState::Fail, ['cleaned_up' => true], $sensitivity,
                    new SeamError('CLEANUP_FAILED', 'The negative-control workspace has not been confirmed removed.', $revision->id));
            }
        }

        return new StepResult(VerificationState::Pass, ['protected_test_unchanged' => true, 'approved_scope_only' => true], ['candidate' => $candidate]);
    }
}
