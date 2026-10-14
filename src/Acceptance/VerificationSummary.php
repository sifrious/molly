<?php

namespace Sifrious\Molly\Acceptance;

final readonly class VerificationSummary
{
    public function __construct(
        public int $productFailures,
        public int $harnessFailures,
        public int $permissionBlocks,
        public int $prerequisiteBlocks,
        public int $evidenceIncomplete,
        public int $passes,
        public int $approvedNa,
        public int $notRun,
        public bool $releaseComplete,
    ) {}

    /** @param  list<VerificationCheckResult>  $results */
    public static function fromResults(array $results): self
    {
        $count = fn (CheckOutcome $outcome): int => count(array_filter(
            $results,
            fn (VerificationCheckResult $result): bool => $result->outcome === $outcome,
        ));
        $passes = $count(CheckOutcome::Pass);
        $approved = $count(CheckOutcome::ApprovedNa);

        return new self(
            $count(CheckOutcome::ProductFail),
            $count(CheckOutcome::HarnessFail),
            $count(CheckOutcome::BlockedVerifierPermission),
            $count(CheckOutcome::BlockedPrerequisite),
            $count(CheckOutcome::EvidenceIncomplete),
            $passes,
            $approved,
            $count(CheckOutcome::NotRun),
            $results !== [] && $passes + $approved === count($results),
        );
    }

    /** @param  list<VerificationCheckResult>  $results */
    public static function stage(string $stage, array $results): array
    {
        $owned = array_values(array_filter(
            $results,
            fn (VerificationCheckResult $result): bool => $result->stage === $stage,
        ));

        return ['stage' => $stage] + self::fromResults($owned)->toArray();
    }

    public function exitCode(VerificationMode $mode): int
    {
        if ($this->productFailures > 0) {
            return 1;
        }
        if ($this->harnessFailures > 0) {
            return 2;
        }
        if ($this->prerequisiteBlocks > 0 || $this->evidenceIncomplete > 0 || $this->notRun > 0) {
            return 3;
        }
        if ($this->permissionBlocks > 0 && $mode !== VerificationMode::Permissionless) {
            return 3;
        }

        return 0;
    }

    /** @return array<string, int|bool> */
    public function toArray(): array
    {
        return [
            'product_failures' => $this->productFailures,
            'harness_failures' => $this->harnessFailures,
            'permission_blocks' => $this->permissionBlocks,
            'prerequisite_blocks' => $this->prerequisiteBlocks,
            'evidence_incomplete' => $this->evidenceIncomplete,
            'passes' => $this->passes,
            'approved_na' => $this->approvedNa,
            'not_run' => $this->notRun,
            'release_complete' => $this->releaseComplete,
        ];
    }
}
