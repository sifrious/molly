<?php

namespace Sifrious\Molly\Acceptance;

use InvalidArgumentException;

final readonly class ProbeResult
{
    /**
     * @param  list<string>  $evidencePaths
     * @param  list<CheckAssertion>  $assertions
     */
    public function __construct(
        public ProbeDisposition $disposition,
        public CheckOutcome $outcome,
        public string $reasonCode,
        public string $message,
        public array $evidencePaths = [],
        public array $assertions = [],
    ) {}

    /**
     * @param  list<string>  $evidencePaths
     * @param  list<CheckAssertion>  $assertions
     */
    public static function complete(CheckOutcome $outcome, string $reasonCode, string $message, array $evidencePaths = [], array $assertions = []): self
    {
        return new self(ProbeDisposition::Complete, $outcome, $reasonCode, $message, $evidencePaths, $assertions);
    }

    /** @param  list<CheckAssertion>  $assertions */
    public static function needsNative(string $reasonCode, string $message, array $assertions = []): self
    {
        return new self(ProbeDisposition::NeedsNative, CheckOutcome::NotRun, $reasonCode, $message, [], $assertions);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $disposition = ProbeDisposition::tryFrom((string) ($data['disposition'] ?? ''));
        $outcome = CheckOutcome::tryFrom((string) ($data['outcome'] ?? CheckOutcome::NotRun->value));
        if ($disposition === null || $outcome === null || ! is_string($data['reason_code'] ?? null) || ! is_string($data['message'] ?? null)) {
            throw new InvalidArgumentException('PROBE_RESULT_INVALID: A check observation needs disposition, reason_code, and message.');
        }

        return new self($disposition, $outcome, $data['reason_code'], $data['message']);
    }
}
