<?php

namespace Sifrious\Molly\Knowledge;

final readonly class GraphSource
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $namespace,
        public string $version,
        public string $type,
        public string $key,
        public string $title,
        public ?string $location = null,
        public ?string $revision = null,
        public ?string $digest = null,
        public array $metadata = [],
    ) {}

    public function id(): string
    {
        return hash('sha256', implode("\0", [$this->namespace, $this->version, $this->type, $this->key]));
    }
}
