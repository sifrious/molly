<?php

namespace Sifrious\Molly\Verification;

enum VerificationState: string
{
    case Pass = 'PASS';
    case Fail = 'FAIL';
    case ReviewRequired = 'REVIEW_REQUIRED';
    case NotRun = 'NOT_RUN';

    public static function fromObserved(?string $status): self
    {
        return match ($status) {
            'passed', 'PASS' => self::Pass,
            'failed', 'FAIL' => self::Fail,
            'review_required', 'REVIEW_REQUIRED' => self::ReviewRequired,
            default => self::NotRun,
        };
    }
}
