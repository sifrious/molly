<?php

namespace Sifrious\Molly\Knowledge;

final readonly class GraphResult
{
    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    public function __construct(
        public string $namespace,
        public string $version,
        public string $concept,
        public array $nodes,
        public array $edges,
        public bool $truncated,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'namespace' => $this->namespace,
            'version' => $this->version,
            'concept' => $this->concept,
            'nodes' => $this->nodes,
            'edges' => $this->edges,
            'truncated' => $this->truncated,
        ];
    }
}
