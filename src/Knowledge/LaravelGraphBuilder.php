<?php

namespace Sifrious\Molly\Knowledge;

use RuntimeException;

/**
 * Deterministic accumulate helpers for Laravel documentation graphs.
 * Finalizes to the public array shape used by existing Laravel*Graph builders.
 */
final class LaravelGraphBuilder
{
    /** @var array<string, GraphSource> */
    private array $sources = [];

    /** @var array<string, GraphNode> */
    private array $nodes = [];

    /** @var array<string, GraphEdge> */
    private array $edges = [];

    public function __construct(
        public readonly string $namespace,
        public readonly string $version,
    ) {}

    public function addSource(GraphSource $source): GraphSource
    {
        $this->assertScope($source->namespace, $source->version);
        $this->sources[$source->id()] = $source;

        return $source;
    }

    /**
     * @param  list<string>  $sourceIds
     * @param  array<string, mixed>  $metadata
     */
    public function addNode(string $type, string $key, string $label, array $sourceIds, array $metadata = []): GraphNode
    {
        $node = new GraphNode($this->namespace, $this->version, $type, $key, $label, $sourceIds, $metadata);
        $this->assertSources($sourceIds);
        $this->nodes[$node->id()] = $node;

        return $node;
    }

    /**
     * @param  list<string>  $sourceIds
     * @param  array<string, mixed>  $metadata
     */
    public function addEdge(string $relation, GraphNode|string $from, GraphNode|string $to, array $sourceIds, array $metadata = []): GraphEdge
    {
        $fromId = $from instanceof GraphNode ? $from->id() : $from;
        $toId = $to instanceof GraphNode ? $to->id() : $to;
        $this->assertSources($sourceIds);
        if (! isset($this->nodes[$fromId], $this->nodes[$toId])) {
            throw new RuntimeException('KNOWLEDGE_EDGE_INVALID: Every edge must connect nodes in the same snapshot.');
        }
        $edge = new GraphEdge($this->namespace, $this->version, $relation, $fromId, $toId, $sourceIds, $metadata);
        $this->edges[$edge->id()] = $edge;

        return $edge;
    }

    /**
     * @return array{sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>}
     */
    public function finalize(): array
    {
        ksort($this->sources);
        ksort($this->nodes);
        ksort($this->edges);

        return [
            'sources' => array_values($this->sources),
            'nodes' => array_values($this->nodes),
            'edges' => array_values($this->edges),
        ];
    }

    private function assertScope(string $namespace, string $version): void
    {
        if ($namespace !== $this->namespace || $version !== $this->version) {
            throw new RuntimeException('KNOWLEDGE_SCOPE_INVALID: Snapshot records must use one namespace and version.');
        }
    }

    /** @param  list<string>  $sourceIds */
    private function assertSources(array $sourceIds): void
    {
        if ($sourceIds === []) {
            throw new RuntimeException('KNOWLEDGE_PROVENANCE_REQUIRED: Every node and edge needs a source.');
        }
        foreach ($sourceIds as $sourceId) {
            if (! isset($this->sources[$sourceId])) {
                throw new RuntimeException('KNOWLEDGE_SOURCE_INVALID: A node or edge refers to a source outside its snapshot.');
            }
        }
    }
}
