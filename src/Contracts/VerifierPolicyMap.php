<?php

namespace Sifrious\Molly\Contracts;

use InvalidArgumentException;
use Sifrious\Molly\Verification\FailureAction;
use Sifrious\Molly\Verification\VerifierPolicy;

final readonly class VerifierPolicyMap
{
    /**
     * @param  array<string, array{policy: VerifierPolicy, failure_action: FailureAction}>  $entries
     */
    public function __construct(public array $entries)
    {
        foreach ($this->entries as $name => $entry) {
            if (! is_string($name) || $name === '') {
                throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: verifier policy names must be non-empty strings.');
            }
        }
    }

    public static function defaults(): self
    {
        return new self([
            'pest' => ['policy' => VerifierPolicy::Required, 'failure_action' => FailureAction::Retry],
            'protected_test_integrity' => ['policy' => VerifierPolicy::Required, 'failure_action' => FailureAction::Fail],
            'allowed_file_integrity' => ['policy' => VerifierPolicy::Required, 'failure_action' => FailureAction::Fail],
            'sandbox_integrity' => ['policy' => VerifierPolicy::Required, 'failure_action' => FailureAction::Fail],
            'parallel_join' => ['policy' => VerifierPolicy::Required, 'failure_action' => FailureAction::Retry],
            'tarpit' => ['policy' => VerifierPolicy::Required, 'failure_action' => FailureAction::Retry],
            'clever' => ['policy' => VerifierPolicy::Advisory, 'failure_action' => FailureAction::Warn],
            'typesafe' => ['policy' => VerifierPolicy::Advisory, 'failure_action' => FailureAction::Warn],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $entries = [];
        foreach ($data as $name => $entry) {
            if (! is_string($name) || $name === '' || ! is_array($entry) || array_is_list($entry)) {
                throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: verifier_policies must map names to policy objects.');
            }

            $policy = VerifierPolicy::tryFrom(JsonDocument::string($entry, 'policy'));
            $action = FailureAction::tryFrom(JsonDocument::string($entry, 'failure_action'));
            if ($policy === null || $action === null) {
                throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: verifier_policies.{$name} needs a known policy and failure_action.");
            }

            $entries[$name] = ['policy' => $policy, 'failure_action' => $action];
        }

        return new self($entries);
    }

    /** @return array<string, array{policy: string, failure_action: string}> */
    public function toArray(): array
    {
        $data = [];
        foreach ($this->entries as $name => $entry) {
            $data[$name] = [
                'policy' => $entry['policy']->value,
                'failure_action' => $entry['failure_action']->value,
            ];
        }

        return $data;
    }
}
