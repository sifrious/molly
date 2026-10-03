<?php

namespace Sifrious\Molly\Acceptance;

enum CheckOutcome: string
{
    case Pass = 'PASS';
    case ProductFail = 'PRODUCT_FAIL';
    case HarnessFail = 'HARNESS_FAIL';
    case BlockedVerifierPermission = 'BLOCKED_VERIFIER_PERMISSION';
    case BlockedPrerequisite = 'BLOCKED_PREREQUISITE';
    case EvidenceIncomplete = 'EVIDENCE_INCOMPLETE';
    case NotRun = 'NOT_RUN';
    case ApprovedNa = 'APPROVED_NA';

    public function exitCode(): int
    {
        return match ($this) {
            self::Pass, self::ApprovedNa => 0,
            self::ProductFail => 1,
            self::HarnessFail => 2,
            default => 3,
        };
    }

    /**
     * The outcome a gate record may store. Only a product failure is FAIL.
     * The gate still rejects anything other than PASS, so a permission block
     * keeps the release incomplete without counting as a Molly failure.
     */
    public function acceptanceRecordOutcome(): string
    {
        return match ($this) {
            self::Pass => 'PASS',
            self::ProductFail => 'FAIL',
            self::EvidenceIncomplete, self::NotRun => 'UNVERIFIED',
            default => 'BLOCKED',
        };
    }
}
