<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphEdge;
use Sifrious\Molly\Knowledge\GraphNode;
use Sifrious\Molly\Knowledge\GraphSource;
use Sifrious\Molly\Knowledge\LaravelContainerGraph;
use Sifrious\Molly\Knowledge\LaravelQueueGraph;
use Sifrious\Molly\Knowledge\LaravelRoutingGraph;
use Sifrious\Molly\Knowledge\LaravelTestingGraph;
use Sifrious\Molly\Knowledge\LaravelValidationGraph;
use Sifrious\Molly\Knowledge\LaravelVersion;

final class IndexLaravelKnowledge
{
    public function __construct(
        private Graph $graph,
        private LaravelQueueGraph $queues,
        private LaravelRoutingGraph $routing,
        private LaravelTestingGraph $testing,
        private LaravelValidationGraph $validation,
        private LaravelContainerGraph $container,
        private LaravelVersion $versions,
    ) {}

    /** @return array<string, mixed> */
    public function handle(?string $requestedVersion = null): array
    {
        $version = $this->versions->current($requestedVersion);
        $snapshot = $this->merge(
            $this->merge($this->queues->build($version), $this->routing->build($version)),
            $this->merge(
                $this->merge($this->testing->build($version), $this->validation->build($version)),
                $this->container->build($version),
            ),
        );
        $counts = $this->graph->replace('laravel', $version, $snapshot['sources'], $snapshot['nodes'], $snapshot['edges']);

        return ['namespace' => 'laravel', 'version' => $version, 'database' => $this->graph->path(), ...$counts];
    }

    /**
     * @param  array{sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>}  $left
     * @param  array{sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>}  $right
     * @return array{sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>}
     */
    private function merge(array $left, array $right): array
    {
        $sources = [];
        foreach ([...$left['sources'], ...$right['sources']] as $source) {
            $sources[$source->id()] = $source;
        }

        $nodes = [];
        foreach ([...$left['nodes'], ...$right['nodes']] as $node) {
            if (isset($nodes[$node->id()])) {
                $existing = $nodes[$node->id()];
                $nodes[$node->id()] = new GraphNode(
                    $existing->namespace,
                    $existing->version,
                    $existing->type,
                    $existing->key,
                    $existing->label,
                    array_values(array_unique([...$existing->sourceIds, ...$node->sourceIds])),
                    $existing->metadata,
                );
            } else {
                $nodes[$node->id()] = $node;
            }
        }

        $edges = [];
        foreach ([...$left['edges'], ...$right['edges']] as $edge) {
            $edges[$edge->id()] = $edge;
        }

        return ['sources' => array_values($sources), 'nodes' => array_values($nodes), 'edges' => array_values($edges)];
    }
}
