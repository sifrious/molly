<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Seams\SeamError;
use Sifrious\Molly\Seams\SeamSteps;
use Sifrious\Molly\Seams\StepHandler;
use Sifrious\Molly\Seams\StepResult;
use Sifrious\Molly\Verification\VerificationState;

final class CheckSeamSensitivity implements StepHandler
{
    public function __construct(private DetectFalseGreen $controls) {}

    public function argumentSchema(): array
    {
        return SeamSteps::emptyArguments();
    }

    public function handle(SeamRevision $revision, Run $attempt, array $arguments, string $evidenceDirectory): StepResult
    {
        $snapshot = $revision->snapshot;
        $control = $snapshot['preview']['contract']['negative_control'];
        $result = $this->controls->targeted($snapshot['workspace'], $snapshot['preview']['path'], $control, $evidenceDirectory);

        return new StepResult(VerificationState::from($result['state']), ['sensitive_case' => $control['case_id']], $result,
            $result['reason'] === null ? null : new SeamError($result['reason'], 'The reviewed mutation must break its named behavior assertion in an otherwise working application.', $control['path']));
    }
}
