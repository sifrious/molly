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
}
