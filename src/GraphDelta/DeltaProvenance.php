<?php

namespace Sifrious\Molly\GraphDelta;

use InvalidArgumentException;

final readonly class DeltaProvenance
{
    public function __construct(
        public string $packHash,
        public string $package,
        public ?string $exactVersion = null,
        public ?string $repositoryRevision = null,
        public ?string $documentationRevision = null,
    ) {
        if ($this->packHash === '' || $this->package === '') {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: pack_hash and package are required.');
        }
    }

    /** @return array{pack_hash: string, package: string, exact_version: ?string, repository_revision: ?string, documentation_revision: ?string} */
    public function toArray(): array
    {
        return [
            'pack_hash' => $this->packHash,
            'package' => $this->package,
            'exact_version' => $this->exactVersion,
            'repository_revision' => $this->repositoryRevision,
            'documentation_revision' => $this->documentationRevision,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::requireString($data, 'pack_hash'),
            self::requireString($data, 'package'),
            self::optionalString($data, 'exact_version'),
            self::optionalString($data, 'repository_revision'),
            self::optionalString($data, 'documentation_revision'),
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
