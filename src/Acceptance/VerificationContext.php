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
        public string $projectRoot = '',
        public ?string $mollyHome = null,
        public ?BloomHostSnapshot $host = null,
        public ?string $taskReference = null,
        public ?string $runReference = null,
    ) {}

    public function withProject(string $projectRoot): self
    {
        return new self(
            $this->candidateSha,
            $this->runId,
            $this->repoRoot,
            $this->evidenceRoot,
            $this->mode,
            $this->hostTelemetryPath,
            $this->observations,
            $this->nativeObservations,
            $this->harnessBroken,
            $this->sharedState,
            $projectRoot,
            $this->mollyHome,
            $this->host,
            $this->taskReference,
            $this->runReference,
        );
    }

    public function withHost(BloomHostSnapshot $host): self
    {
        return new self(
            $this->candidateSha,
            $this->runId,
            $this->repoRoot,
            $this->evidenceRoot,
            $this->mode,
            $this->hostTelemetryPath,
            $this->observations,
            $this->nativeObservations,
            $this->harnessBroken,
            $this->sharedState,
            $this->projectRoot,
            $this->mollyHome,
            $host,
            $this->taskReference,
            $this->runReference,
        );
    }

    public function withMollyHome(string $mollyHome): self
    {
        return new self(
            $this->candidateSha,
            $this->runId,
            $this->repoRoot,
            $this->evidenceRoot,
            $this->mode,
            $this->hostTelemetryPath,
            $this->observations,
            $this->nativeObservations,
            $this->harnessBroken,
            $this->sharedState,
            $this->projectRoot,
            $mollyHome,
            $this->host,
            $this->taskReference,
            $this->runReference,
        );
    }

    public function withSelection(?string $taskReference, ?string $runReference): self
    {
        return new self(
            $this->candidateSha,
            $this->runId,
            $this->repoRoot,
            $this->evidenceRoot,
            $this->mode,
            $this->hostTelemetryPath,
            $this->observations,
            $this->nativeObservations,
            $this->harnessBroken,
            $this->sharedState,
            $this->projectRoot,
            $this->mollyHome,
            $this->host,
            $taskReference,
            $runReference,
        );
    }

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
            $this->projectRoot,
            $this->mollyHome,
            $this->host,
            $this->taskReference,
            $this->runReference,
        );
    }
}
