<?php

namespace Sifrious\Molly\Knowledge;

use RuntimeException;

/** Canonical immutable graph snapshot for one namespace/version. */
final readonly class GraphSnapshot
{
    /**
     * @param  list<GraphSource>  $sources
     * @param  list<GraphNode>  $nodes
     * @param  list<GraphEdge>  $edges
     */
    public function __construct(
        public string $namespace,
        public string $version,
        public array $sources,
        public array $nodes,
        public array $edges,
    ) {
        $this->assertUniqueIds();
        $this->assertScope();
        $sourceIds = [];
        foreach ($this->sources as $source) {
            $sourceIds[$source->id()] = true;
        }
        $nodeIds = [];
        foreach ($this->nodes as $node) {
            $this->assertSources($node->sourceIds, $sourceIds);
            $nodeIds[$node->id()] = true;
        }
        foreach ($this->edges as $edge) {
            $this->assertSources($edge->sourceIds, $sourceIds);
            if (! isset($nodeIds[$edge->from], $nodeIds[$edge->to])) {
                throw new RuntimeException('KNOWLEDGE_EDGE_INVALID: Every edge must connect nodes in the same snapshot.');
            }
        }
    }

    private function assertUniqueIds(): void
    {
        foreach ([
            'source' => $this->sources,
            'node' => $this->nodes,
            'edge' => $this->edges,
        ] as $kind => $records) {
            $seen = [];
            foreach ($records as $record) {
                $id = $record->id();
                if (isset($seen[$id])) {
                    throw new RuntimeException('KNOWLEDGE_ID_DUPLICATE: Duplicate '.$kind.' id in the snapshot.');
                }
                $seen[$id] = true;
            }
        }
    }

    private function assertScope(): void
    {
        foreach ([...$this->sources, ...$this->nodes, ...$this->edges] as $record) {
            if ($record->namespace !== $this->namespace || $record->version !== $this->version) {
                throw new RuntimeException('KNOWLEDGE_SCOPE_INVALID: Snapshot records must use one namespace and version.');
            }
        }
    }

    /**
     * @param  list<string>  $sourceIds
     * @param  array<string, true>  $available
     */
    private function assertSources(array $sourceIds, array $available): void
    {
        if ($sourceIds === []) {
            throw new RuntimeException('KNOWLEDGE_PROVENANCE_REQUIRED: Every node and edge needs a source.');
        }
        foreach ($sourceIds as $sourceId) {
            if (! isset($available[$sourceId])) {
                throw new RuntimeException('KNOWLEDGE_SOURCE_INVALID: A node or edge refers to a source outside its snapshot.');
            }
        }
    }

    /** @return array{namespace: string, version: string, sources: list<array<string, mixed>>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'namespace' => $this->namespace,
            'version' => $this->version,
            'sources' => array_map(fn (GraphSource $source): array => [
                'namespace' => $source->namespace,
                'version' => $source->version,
                'type' => $source->type,
                'key' => $source->key,
                'title' => $source->title,
                'location' => $source->location,
                'revision' => $source->revision,
                'digest' => $source->digest,
                'metadata' => $source->metadata,
            ], $this->sources),
            'nodes' => array_map(fn (GraphNode $node): array => [
                'namespace' => $node->namespace,
                'version' => $node->version,
                'type' => $node->type,
                'key' => $node->key,
                'label' => $node->label,
                'source_ids' => $node->sourceIds,
                'metadata' => $node->metadata,
            ], $this->nodes),
            'edges' => array_map(fn (GraphEdge $edge): array => [
                'namespace' => $edge->namespace,
                'version' => $edge->version,
                'relation' => $edge->relation,
                'from' => $edge->from,
                'to' => $edge->to,
                'source_ids' => $edge->sourceIds,
                'metadata' => $edge->metadata,
            ], $this->edges),
        ];
    }

    /** @param  array<string, mixed>  $payload */
    public static function fromArray(array $payload): self
    {
        foreach (['namespace', 'version', 'sources', 'nodes', 'edges'] as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new RuntimeException('KNOWLEDGE_SNAPSHOT_INVALID: Cached snapshot is missing '.$key.'.');
            }
        }
        if (! is_string($payload['namespace']) || ! is_string($payload['version'])) {
            throw new RuntimeException('KNOWLEDGE_SNAPSHOT_INVALID: namespace and version must be strings.');
        }
        if (! is_array($payload['sources']) || ! is_array($payload['nodes']) || ! is_array($payload['edges'])) {
            throw new RuntimeException('KNOWLEDGE_SNAPSHOT_INVALID: sources, nodes, and edges must be lists.');
        }

        $sources = [];
        foreach ($payload['sources'] as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('KNOWLEDGE_SNAPSHOT_INVALID: Each source must be an object.');
            }
            foreach (['namespace', 'version', 'type', 'key', 'title'] as $required) {
                if (! is_string($row[$required] ?? null)) {
                    throw new RuntimeException('KNOWLEDGE_SNAPSHOT_INVALID: Source field '.$required.' must be a string.');
                }
            }
            if (isset($row['metadata']) && ! is_array($row['metadata'])) {
                throw new RuntimeException('KNOWLEDGE_SNAPSHOT_INVALID: Source metadata must be an object.');
            }
            $sources[] = new GraphSource(
                $row['namespace'], $row['version'], $row['type'], $row['key'], $row['title'],
                is_string($row['location'] ?? null) ? $row['location'] : null,
                is_string($row['revision'] ?? null) ? $row['revision'] : null,
                is_string($row['digest'] ?? null) ? $row['digest'] : null,
                is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
            );
        }

        $nodes = [];
        foreach ($payload['nodes'] as $row) {
            if (! is_array($row) || ! is_array($row['source_ids'] ?? null)) {
                throw new RuntimeException('KNOWLEDGE_SNAPSHOT_INVALID: Each node needs string fields and source_ids list.');
            }
            foreach (['namespace', 'version', 'type', 'key', 'label'] as $required) {
                if (! is_string($row[$required] ?? null)) {
                    throw new RuntimeException('KNOWLEDGE_SNAPSHOT_INVALID: Node field '.$required.' must be a string.');
                }
            }
            $sourceIds = [];
            foreach ($row['source_ids'] as $id) {
                if (! is_string($id)) {
                    throw new RuntimeException('KNOWLEDGE_SNAPSHOT_INVALID: Node source_ids must be strings.');
                }
                $sourceIds[] = $id;
            }
            $nodes[] = new GraphNode(
                $row['namespace'], $row['version'], $row['type'], $row['key'], $row['label'], $sourceIds,
                is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
            );
        }

        $edges = [];
        foreach ($payload['edges'] as $row) {
            if (! is_array($row) || ! is_array($row['source_ids'] ?? null)) {
                throw new RuntimeException('KNOWLEDGE_SNAPSHOT_INVALID: Each edge needs string fields and source_ids list.');
            }
            foreach (['namespace', 'version', 'relation', 'from', 'to'] as $required) {
                if (! is_string($row[$required] ?? null)) {
                    throw new RuntimeException('KNOWLEDGE_SNAPSHOT_INVALID: Edge field '.$required.' must be a string.');
                }
            }
            $sourceIds = [];
            foreach ($row['source_ids'] as $id) {
                if (! is_string($id)) {
                    throw new RuntimeException('KNOWLEDGE_SNAPSHOT_INVALID: Edge source_ids must be strings.');
                }
                $sourceIds[] = $id;
            }
            $edges[] = new GraphEdge(
                $row['namespace'], $row['version'], $row['relation'], $row['from'], $row['to'], $sourceIds,
                is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
            );
        }

        return new self($payload['namespace'], $payload['version'], $sources, $nodes, $edges);
    }
}
