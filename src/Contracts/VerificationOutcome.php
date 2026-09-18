<?php

namespace Sifrious\Molly\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;
use Sifrious\Molly\Verification\FailureAction;
use Sifrious\Molly\Verification\VerificationState;
use Sifrious\Molly\Verification\VerifierPolicy;

final readonly class VerificationOutcome
{
    public const SCHEMA = 'molly.verification_outcome.v1';

    public function __construct(
        public string $verifier,
        public VerificationState $state,
        public VerifierPolicy $policy,
        public FailureAction $failureAction,
        public ?string $diagnosticsRef,
        public string $evidenceDigest,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $finishedAt,
        public bool $anotherAttemptPermitted,
    ) {
        if ($this->verifier === '') {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: verifier is required.');
        }
        if (! preg_match('/\A[a-f0-9]{64}\z/', $this->evidenceDigest)) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: evidence_digest must be a SHA-256 hex digest.');
        }
        if ($this->finishedAt < $this->startedAt) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: finished_at cannot precede started_at.');
        }
        if ($this->policy === VerifierPolicy::Required && $this->failureAction === FailureAction::Warn) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: A required verifier cannot use the warn failure action.');
        }
        if ($this->state === VerificationState::Pass && $this->anotherAttemptPermitted) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: A passing outcome does not permit another attempt.');
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        JsonDocument::requireSchema($data, self::SCHEMA);
        $state = VerificationState::tryFrom(JsonDocument::string($data, 'state'));
        $policy = VerifierPolicy::tryFrom(JsonDocument::string($data, 'policy'));
        $action = FailureAction::tryFrom(JsonDocument::string($data, 'failure_action'));
        if ($state === null || $policy === null || $action === null) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: state, policy, and failure_action must be known values.');
        }

        return new self(
            JsonDocument::string($data, 'verifier'),
            $state,
            $policy,
            $action,
            JsonDocument::optionalString($data, 'diagnostics_ref'),
            JsonDocument::string($data, 'evidence_digest'),
            JsonDocument::time($data, 'started_at'),
            JsonDocument::time($data, 'finished_at'),
            JsonDocument::boolean($data, 'another_attempt_permitted'),
        );
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray(JsonDocument::decode($json));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'verifier' => $this->verifier,
            'state' => $this->state->value,
            'policy' => $this->policy->value,
            'failure_action' => $this->failureAction->value,
            'diagnostics_ref' => $this->diagnosticsRef,
            'evidence_digest' => $this->evidenceDigest,
            'started_at' => JsonDocument::formatTime($this->startedAt),
            'finished_at' => JsonDocument::formatTime($this->finishedAt),
            'another_attempt_permitted' => $this->anotherAttemptPermitted,
        ];
    }

    public function toJson(): string
    {
        return JsonDocument::encode($this->toArray());
    }
}
