<?php

namespace Sifrious\Molly\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class LifecycleEvent
{
    public const SCHEMA = 'molly.lifecycle_event.v1';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public DateTimeImmutable $occurredAt,
        public string $taskId,
        public ?string $runId,
        public array $payload,
        public bool $known,
        public string $schema = self::SCHEMA,
    ) {
        JsonDocument::assertUuid($this->eventId, 'event_id');
        JsonDocument::assertUuid($this->taskId, 'task_id');
        if ($this->runId !== null) {
            JsonDocument::assertUuid($this->runId, 'run_id');
        }
        if ($this->type === '') {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: type is required.');
        }
        if ($this->payload !== [] && array_is_list($this->payload)) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: payload must be an object.');
        }
    }

    public static function make(
        LifecycleEventType $type,
        string $eventId,
        DateTimeImmutable $occurredAt,
        string $taskId,
        ?string $runId = null,
        array $payload = [],
    ): self {
        return new self($eventId, $type->value, $occurredAt, $taskId, $runId, $payload, true);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $schema = $data['schema'] ?? null;
        if (! is_string($schema) || $schema === '') {
            throw new InvalidArgumentException('CONTRACT_SCHEMA_INVALID: A lifecycle event needs a schema.');
        }

        $knownSchema = $schema === self::SCHEMA;
        $type = JsonDocument::string($data, 'type');
        $knownType = LifecycleEventType::tryFrom($type) !== null;

        return new self(
            JsonDocument::uuid($data, 'event_id'),
            $type,
            JsonDocument::time($data, 'occurred_at'),
            JsonDocument::uuid($data, 'task_id'),
            JsonDocument::optionalUuid($data, 'run_id'),
            JsonDocument::object($data, 'payload'),
            $knownSchema && $knownType,
            $schema,
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
            'schema' => $this->schema,
            'event_id' => $this->eventId,
            'type' => $this->type,
            'occurred_at' => JsonDocument::formatTime($this->occurredAt),
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'payload' => $this->payload,
            'known' => $this->known,
        ];
    }

    public function toJson(): string
    {
        return JsonDocument::encode($this->toArray());
    }

    public function type(): ?LifecycleEventType
    {
        return $this->known ? LifecycleEventType::tryFrom($this->type) : null;
    }
}
