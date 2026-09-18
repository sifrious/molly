<?php

namespace Sifrious\Molly\Contracts;

use InvalidArgumentException;

final readonly class ExecutionTargetRequest
{
    public function __construct(
        public ExecutionTargetKind $kind,
        public ?string $targetId = null,
        public ?string $reason = null,
    ) {
        if ($this->kind === ExecutionTargetKind::Orb && ($this->targetId === null || $this->targetId === '')) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: An Orb execution target needs a target_id.');
        }
        if ($this->kind === ExecutionTargetKind::Local && $this->targetId !== null) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: A local execution target cannot name a remote target_id.');
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $kind = ExecutionTargetKind::tryFrom(JsonDocument::string($data, 'kind'));
        if ($kind === null) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: execution_target.kind must be local or orb.');
        }

        return new self(
            $kind,
            JsonDocument::optionalString($data, 'target_id'),
            JsonDocument::optionalString($data, 'reason'),
        );
    }

    public static function local(?string $reason = null): self
    {
        return new self(ExecutionTargetKind::Local, null, $reason ?? 'Local execution is the default.');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'target_id' => $this->targetId,
            'reason' => $this->reason,
        ];
    }
}
