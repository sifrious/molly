<?php

namespace Sifrious\Molly\Acceptance;

use DateTimeImmutable;
use Sifrious\Molly\Contracts\JsonDocument;

final readonly class VerificationCheckResult
{
    /**
     * @param  list<string>  $requiredPermissions
     * @param  array<string, string>  $observedPermissions
     * @param  list<string>  $evidencePaths
     * @param  list<CheckAssertion>  $assertions
     */
    public function __construct(
        public string $checkId,
        public string $candidateSha,
        public string $runId,
        public string $interface,
        public CheckOutcome $outcome,
        public string $reasonCode,
        public string $message,
        public array $requiredPermissions,
        public array $observedPermissions,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $finishedAt,
        public int $exitCode,
        public array $evidencePaths,
        public string $stage = 'M04',
        public int $attempt = 1,
        public array $assertions = [],
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $outcome = CheckOutcome::tryFrom((string) ($data['outcome'] ?? ''));
        if ($outcome === null) {
            throw new \InvalidArgumentException('CHECK_RESULT_INVALID: outcome is not a known verification outcome.');
        }

        return new self(
            (string) $data['check_id'],
            (string) $data['candidate_sha'],
            (string) $data['run_id'],
            (string) $data['interface'],
            $outcome,
            (string) $data['reason_code'],
            (string) $data['message'],
            array_values($data['required_permissions'] ?? []),
            $data['observed_permissions'] ?? [],
            new DateTimeImmutable((string) $data['started_at']),
            new DateTimeImmutable((string) $data['finished_at']),
            (int) $data['exit_code'],
            array_values($data['evidence_paths'] ?? []),
            (string) ($data['stage'] ?? 'M04'),
            (int) ($data['attempt'] ?? 1),
            array_values(array_map(
                fn (mixed $assertion): CheckAssertion => CheckAssertion::fromArray(is_array($assertion) ? $assertion : []),
                is_array($data['assertions'] ?? null) ? $data['assertions'] : [],
            )),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'check_id' => $this->checkId,
            'candidate_sha' => $this->candidateSha,
            'run_id' => $this->runId,
            'interface' => $this->interface,
            'stage' => $this->stage,
            'outcome' => $this->outcome->value,
            'acceptance_record_outcome' => $this->outcome->acceptanceRecordOutcome(),
            'reason_code' => $this->reasonCode,
            'message' => $this->message,
            'required_permissions' => $this->requiredPermissions,
            'observed_permissions' => $this->observedPermissions,
            'started_at' => JsonDocument::formatTime($this->startedAt),
            'finished_at' => JsonDocument::formatTime($this->finishedAt),
            'exit_code' => $this->exitCode,
            'evidence_paths' => $this->evidencePaths,
            'attempt' => $this->attempt,
            'assertions' => array_map(fn (CheckAssertion $assertion): array => $assertion->toArray(), $this->assertions),
        ];
    }
}
