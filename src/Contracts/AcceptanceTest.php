<?php

namespace Sifrious\Molly\Contracts;

use InvalidArgumentException;

final readonly class AcceptanceTest
{
    public function __construct(
        public string $id,
        public string $path,
        public string $digest,
    ) {
        JsonDocument::assertUuid($this->id, 'acceptance_tests.id');
        JsonDocument::relativePath($this->path, 'acceptance_tests.path');
        if (! str_starts_with($this->path, 'tests/') || ! str_ends_with($this->path, '.php')) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: acceptance_tests.path must be a PHP file under tests/.');
        }
        if (! preg_match('/\A[a-f0-9]{64}\z/', $this->digest)) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: acceptance_tests.digest must be a SHA-256 hex digest.');
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            JsonDocument::uuid($data, 'id'),
            JsonDocument::string($data, 'path'),
            JsonDocument::string($data, 'digest'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'digest' => $this->digest,
        ];
    }
}
