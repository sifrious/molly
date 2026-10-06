<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Seams\SeamError;
use Sifrious\Molly\Seams\SeamSteps;
use Sifrious\Molly\Seams\StepHandler;
use Sifrious\Molly\Seams\StepResult;
use Sifrious\Molly\Verification\VerificationState;
use Sifrious\Molly\Workspace;
use Sifrious\Molly\Workspace\Directory;

final class VerifySeamBehavior implements StepHandler
{
    public function __construct(private VerifyChanges $verify, private RecordRedBaseline $red, private GuardSeamRevision $guard) {}

    public function argumentSchema(): array
    {
        return SeamSteps::emptyArguments();
    }

    public function handle(SeamRevision $revision, Run $attempt, array $arguments, string $evidenceDirectory): StepResult
    {
        $snapshot = $revision->snapshot;
        $workspace = $snapshot['workspace'];
        $preview = $snapshot['preview'];
        $phase = $attempt->report['seam']['step_id'];
        $candidate = $this->guard->handle($revision);
        if ($phase === 'pre' && $candidate !== $snapshot['baseline']) {
            throw new SeamError('STALE_EVIDENCE', 'PRE must run before production changes, on the recorded baseline.', $revision->id);
        }
        $result = $this->verify->handle($workspace, $preview['path'], $evidenceDirectory.'/candidate');
        $expected = ['phase' => $phase, 'case_ids' => $preview['cases']];
        $observed = ['verification' => $result, 'candidate' => $candidate];
        if ($phase === 'post') {
            $passed = ($result['status'] ?? null) === 'passed' && ($result['tests'] ?? 0) === count($preview['cases']);

            return new StepResult($passed ? VerificationState::Pass : VerificationState::Fail, $expected, $observed,
                $passed ? null : new SeamError('BEHAVIOR_ASSERTION_FAILED', 'The candidate must pass every protected behavior case.', $preview['path']));
        }
        if (($preview['contract']['verification_path'] ?? 'baseline_red') === 'already_implemented') {
            $passed = ($result['status'] ?? null) === 'passed' && ($result['tests'] ?? 0) === count($preview['cases']);

            return new StepResult($passed ? VerificationState::Pass : VerificationState::Fail,
                [...$expected, 'path' => 'already_implemented', 'historical_red' => false], $observed,
                $passed ? null : new SeamError('RED_REASON_MISMATCH', 'The declared existing behavior did not pass at intake.', $preview['path']));
        }
        $classification = $this->red->classify($result, $workspace, $preview['path']);
        $observed['classification'] = $classification;
        if (($result['status'] ?? null) === 'passed') {
            return new StepResult(VerificationState::ReviewRequired, $expected, $observed,
                new SeamError('PRE_ALREADY_GREEN', 'Record an already-implemented verification path in a new revision. No historical RED was observed.', $preview['path']));
        }
        $missing = $classification['classification'] === 'missing_behavior'
            && ($result['tests'] ?? 0) === count($preview['cases'])
            && ($result['failures'] ?? 0) === count($preview['cases']) && ($result['errors'] ?? 0) === 0;
        if (! $missing) {
            return new StepResult(VerificationState::Fail, $expected, $observed,
                new SeamError('RED_REASON_MISMATCH', 'Every new behavior case must fail by assertion, with no bootstrap or dependency errors.', $preview['path']));
        }
        // A second executable test proves the declared baseline response. A different failure cannot stand in for it.
        $probe = dirname($preview['path']).'/MollyBaseline'.str_replace('-', '', $attempt->id).'Test.php';
        $files = new Workspace($workspace);
        if ($files->read([$probe])[$probe] !== null) {
            throw new SeamError('OUTPUT_CONFLICT', 'The temporary baseline probe path already exists.', $probe);
        }
        try {
            Directory::replaceFile($workspace.'/'.$probe, $preview['baseline_contents'], 'EVENT_PERSIST_FAILED');
            $observed['baseline_probe'] = $this->verify->handle($workspace, $probe, $evidenceDirectory.'/baseline');
        } finally {
            if (is_file($workspace.'/'.$probe) && ! unlink($workspace.'/'.$probe)) {
                throw new SeamError('CLEANUP_FAILED', 'Remove the temporary baseline probe before retrying.', $probe);
            }
        }
        $passed = ($observed['baseline_probe']['status'] ?? null) === 'passed'
            && ($observed['baseline_probe']['tests'] ?? 0) === count($preview['cases']);

        return new StepResult($passed ? VerificationState::Pass : VerificationState::Fail,
            [...$expected, 'test_outcome' => 'FAIL', 'baseline_response' => 'PASS'], $observed,
            $passed ? null : new SeamError('RED_REASON_MISMATCH', 'The observed baseline differs from the explicitly expected pre-implementation behavior.', $preview['path']));
    }
}
