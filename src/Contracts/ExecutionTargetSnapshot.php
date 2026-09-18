<?php

namespace Sifrious\Molly\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ExecutionTargetSnapshot
{
    public const SCHEMA = 'molly.execution_target_snapshot.v1';

    /**
     * @param  list<string>  $capabilities
     */
    public function __construct(
        public ExecutionTargetKind $kind,
        public string $targetId,
        public array $capabilities,
        public bool $sandbox,
        public string $provider,
        public DateTimeImmutable $observedAt,
        public string $selectionReason,
    ) {
        if ($this->kind === ExecutionTargetKind::Orb && $this->targetId === 'local') {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: An Orb snapshot cannot use the local target_id.');
        }
        if ($this->kind === ExecutionTargetKind::Local && $this->targetId !== 'local') {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: A local snapshot must use target_id local.');
        }
        if ($this->provider === '' || $this->selectionReason === '') {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: provider and selection_reason are required.');
        }
        if (count(array_unique($this->capabilities)) !== count($this->capabilities)) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: capabilities cannot contain duplicates.');
        }
        foreach ($this->capabilities as $capability) {
            if (! preg_match('/\A[a-z][a-z0-9_]{0,49}\z/', $capability)) {
                throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: capability names use lowercase letters, numbers, and underscores.');
            }
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        JsonDocument::requireSchema($data, self::SCHEMA);
        $kind = ExecutionTargetKind::tryFrom(JsonDocument::string($data, 'kind'));
        if ($kind === null) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: kind must be local or orb.');
        }

        return new self(
            $kind,
            JsonDocument::string($data, 'target_id'),
            JsonDocument::stringList($data, 'capabilities'),
            JsonDocument::boolean($data, 'sandbox'),
            JsonDocument::string($data, 'provider'),
            JsonDocument::time($data, 'observed_at'),
            JsonDocument::string($data, 'selection_reason'),
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
            'kind' => $this->kind->value,
            'target_id' => $this->targetId,
            'capabilities' => $this->capabilities,
            'sandbox' => $this->sandbox,
            'provider' => $this->provider,
            'observed_at' => JsonDocument::formatTime($this->observedAt),
            'selection_reason' => $this->selectionReason,
        ];
    }

    public function toJson(): string
    {
        return JsonDocument::encode($this->toArray());
    }
}
