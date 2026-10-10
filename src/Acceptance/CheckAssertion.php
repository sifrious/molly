<?php

namespace Sifrious\Molly\Acceptance;

/**
 * One comparison Molly made while verifying a check.
 * The result is pass, fail, or not_observed. It is not an acceptance outcome.
 */
final readonly class CheckAssertion
{
    public function __construct(
        public string $candidateSha,
        public string $runId,
        public string $assertion,
        public string $expected,
        public string $actual,
        public string $sourceArtifact,
        public string $result,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['candidate_sha'] ?? ''),
            (string) ($data['run_id'] ?? ''),
            (string) ($data['assertion'] ?? ''),
            (string) ($data['expected'] ?? ''),
            (string) ($data['actual'] ?? ''),
            (string) ($data['source_artifact'] ?? ''),
            (string) ($data['result'] ?? 'not_observed'),
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'candidate_sha' => $this->candidateSha,
            'run_id' => $this->runId,
            'assertion' => $this->assertion,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'source_artifact' => $this->sourceArtifact,
            'result' => $this->result,
        ];
    }
}
