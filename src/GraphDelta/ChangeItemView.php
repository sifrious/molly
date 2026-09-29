<?php

namespace Sifrious\Molly\GraphDelta;

use InvalidArgumentException;

final readonly class ChangeItemView
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $key,
        public ChangeType $change,
        public array $fields,
        public Freshness $freshness,
        public VerificationStatus $verification,
        public ?string $sourcePath = null,
        public ?string $sourceUrl = null,
    ) {
        if ($this->id === '' || $this->type === '' || $this->key === '') {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: id, type, and key are required.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'key' => $this->key,
            'change' => $this->change->value,
            'fields' => $this->fields,
            'freshness' => $this->freshness->value,
            'verification' => $this->verification->value,
            'source_path' => $this->sourcePath,
            'source_url' => $this->sourceUrl,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $change = ChangeType::tryFrom($data['change'] ?? '');
        if ($change === null) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: change must be added, removed, or changed.');
        }

        $freshness = Freshness::tryFrom($data['freshness'] ?? '');
        if ($freshness === null) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: freshness must be a known value.');
        }

        $verification = VerificationStatus::tryFrom($data['verification'] ?? '');
        if ($verification === null) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: verification must be a known value.');
        }

        return new self(
            self::requireString($data, 'id'),
            self::requireString($data, 'type'),
            self::requireString($data, 'key'),
            $change,
            is_array($data['fields'] ?? null) ? $data['fields'] : [],
            $freshness,
            $verification,
            self::optionalString($data, 'source_path'),
            self::optionalString($data, 'source_url'),
        );
    }

    /** @param array<string, mixed> $data */
    private static function requireString(array $data, string $key): string
    {
        if (! is_string($data[$key] ?? null) || $data[$key] === '') {
            throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key} must be a non-empty string.");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function optionalString(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        if (! is_string($data[$key]) || $data[$key] === '') {
            throw new InvalidArgumentException("CONTRACT_FIELD_INVALID: {$key} must be a non-empty string or null.");
        }

        return $data[$key];
    }
}
