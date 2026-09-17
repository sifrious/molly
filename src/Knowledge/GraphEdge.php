<?php

namespace Sifrious\Molly\Knowledge;

final readonly class GraphEdge
{
    /**
     * @param  list<string>  $sourceIds
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $namespace,
        public string $version,
        public string $relation,
        public string $from,
        public string $to,
        public array $sourceIds,
        public array $metadata = [],
    ) {}

    public function id(): string
    {
        return hash('sha256', implode("\0", [$this->namespace, $this->version, $this->relation, $this->from, $this->to]));
    }
}
