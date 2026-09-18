<?php

namespace Sifrious\Molly\Contracts;

use InvalidArgumentException;

final readonly class RepositoryIdentity
{
    public function __construct(
        public string $provider,
        public string $owner,
        public string $name,
        public ?string $url = null,
    ) {
        if (! in_array($this->provider, ['github', 'git'], true)) {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: repository.provider must be github or git.');
        }
        if ($this->owner === '' || $this->name === '') {
            throw new InvalidArgumentException('CONTRACT_FIELD_INVALID: repository.owner and repository.name are required.');
        }
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            JsonDocument::string($data, 'provider'),
            JsonDocument::string($data, 'owner'),
            JsonDocument::string($data, 'name'),
            JsonDocument::optionalString($data, 'url'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'owner' => $this->owner,
            'name' => $this->name,
            'url' => $this->url,
        ];
    }
}
