<?php

namespace Sifrious\Molly\Acceptance;

use DateTimeImmutable;
use DateTimeZone;

final class VerificationResultClassifier
{
    /**
     * @return array{0: VerificationCheckResult, 1: bool} The result, and whether the native observer still has to run.
     */
    public function classify(
        VerificationCheckDefinition $check,
        ProbeResult $probe,
        VerifierPermissionSnapshot $snapshot,
        VerificationContext $context,
        DateTimeImmutable $startedAt,
        int $attempt,
    ): array {
        if ($probe->disposition === ProbeDisposition::Complete || $this->terminal($probe->outcome)) {
            return [$this->result($check, $probe->outcome, $probe->reasonCode, $probe->message, $snapshot, $context, $startedAt, $attempt, $probe->evidencePaths, $probe->assertions), false];
        }

        if ($context->mode === VerificationMode::Permissionless) {
            return [$this->result(
                $check,
                CheckOutcome::EvidenceIncomplete,
                'NATIVE_OBSERVATION_DISABLED',
                $probe->message.' Permissionless mode did not drive the Bloom UI and did not request '.$this->permissionText($snapshot).'.',
                $snapshot,
                $context,
                $startedAt,
                $attempt,
                [],
                $probe->assertions,
            ), false];
        }

        $missing = $snapshot->missing($check->requiredPermissions);
        if ($missing !== []) {
            $names = implode(', ', array_map(fn (PermissionKind $kind): string => $kind->label(), $missing));

            return [$this->result(
                $check,
                CheckOutcome::BlockedVerifierPermission,
                'VERIFIER_PERMISSION_MISSING',
                $names.' is not granted for '.$snapshot->process->label().'. Molly did not grant it. '.$check->id.' is blocked in the verifier environment, not failed as a Molly product.',
                $snapshot,
                $context,
                $startedAt,
                $attempt,
                [],
                $probe->assertions,
            ), false];
        }

        if ($snapshot->notObserved($check->requiredPermissions) !== []) {
            return [$this->result(
                $check,
                CheckOutcome::EvidenceIncomplete,
                'PERMISSION_STATE_NOT_OBSERVED',
                $probe->message.' Molly did not observe the permission state, so it did not call that permission granted or denied.',
                $snapshot,
                $context,
                $startedAt,
                $attempt,
                [],
                $probe->assertions,
            ), false];
        }

        return [$this->result($check, CheckOutcome::NotRun, 'NATIVE_OBSERVATION_PENDING', $probe->message, $snapshot, $context, $startedAt, $attempt, [], $probe->assertions), true];
    }

    public function finishNative(
        VerificationCheckDefinition $check,
        ProbeResult $native,
        VerifierPermissionSnapshot $snapshot,
        VerificationContext $context,
        DateTimeImmutable $startedAt,
        int $attempt,
    ): VerificationCheckResult {
        $outcome = $native->disposition === ProbeDisposition::NeedsNative ? CheckOutcome::HarnessFail : $native->outcome;
        $reason = $outcome === CheckOutcome::HarnessFail ? 'NATIVE_OBSERVATION_INVALID' : $native->reasonCode;
        $message = $outcome === CheckOutcome::HarnessFail
            ? $check->id.' asked for another native observation after its permission was already granted.'
            : $native->message;

        return $this->result($check, $outcome, $reason, $message, $snapshot, $context, $startedAt, $attempt, $native->evidencePaths, $native->assertions);
    }

    public function harness(
        VerificationCheckDefinition $check,
        string $reasonCode,
        string $message,
        VerifierPermissionSnapshot $snapshot,
        VerificationContext $context,
        DateTimeImmutable $startedAt,
        int $attempt,
    ): VerificationCheckResult {
        return $this->result($check, CheckOutcome::HarnessFail, $reasonCode, $message, $snapshot, $context, $startedAt, $attempt);
    }

    /**
     * @param  list<string>  $evidencePaths
     * @param  list<CheckAssertion>  $assertions
     */
    private function result(
        VerificationCheckDefinition $check,
        CheckOutcome $outcome,
        string $reasonCode,
        string $message,
        VerifierPermissionSnapshot $snapshot,
        VerificationContext $context,
        DateTimeImmutable $startedAt,
        int $attempt,
        array $evidencePaths = [],
        array $assertions = [],
    ): VerificationCheckResult {
        return new VerificationCheckResult(
            $check->id,
            $context->candidateSha,
            $context->runId,
            $check->interface,
            $outcome,
            $reasonCode,
            $message,
            array_map(fn (PermissionKind $kind): string => $kind->value, $check->requiredPermissions),
            [
                PermissionKind::ScreenRecording->value => $snapshot->screenRecording->value,
                PermissionKind::Accessibility->value => $snapshot->accessibility->value,
            ],
            $startedAt,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $outcome->exitCode(),
            $evidencePaths,
            $check->stage,
            $attempt,
            $assertions,
        );
    }

    private function permissionText(VerifierPermissionSnapshot $snapshot): string
    {
        return 'Screen Recording ('.$snapshot->screenRecording->value.') or Accessibility ('.$snapshot->accessibility->value.')';
    }

    private function terminal(CheckOutcome $outcome): bool
    {
        return $outcome === CheckOutcome::ProductFail || $outcome === CheckOutcome::HarnessFail;
    }
}
