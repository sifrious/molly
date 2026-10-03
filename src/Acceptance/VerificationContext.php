<?php

namespace Sifrious\Molly\Acceptance;

final readonly class VerificationContext
{
    /**
     * @param  array<string, array<string, mixed>>  $observations
     * @param  array<string, array<string, mixed>>  $nativeObservations
     * @param  list<string>  $harnessBroken
     * @param  array<string, mixed>  $sharedState
     */
    public function __construct(
        public string $candidateSha,
        public string $runId,
        public string $repoRoot,
        public string $evidenceRoot,
        public VerificationMode $mode,
        public ?string $hostTelemetryPath = null,
        public array $observations = [],
        public array $nativeObservations = [],
        public array $harnessBroken = [],
        public array $sharedState = [],
    ) {}

    public function forRun(string $runId, string $candidateSha, string $evidenceRoot, VerificationMode $mode): self
    {
        return new self(
            $candidateSha,
            $runId,
            $this->repoRoot,
            $evidenceRoot,
            $mode,
            $this->hostTelemetryPath,
            $this->observations,
            $this->nativeObservations,
            $this->harnessBroken,
            $this->sharedState,
        );
    }
}
