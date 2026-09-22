<?php

namespace Sifrious\Molly\Knowledge;

use ReflectionClass;
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

    public function assertGuideTitleHasLaravelMajor(string $title, string $guideLabel): void
    {
        if (! preg_match('/Laravel \d+ /', $title)) {
            throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The bundled '.$guideLabel.' guide needs a Laravel major version in its title.');
        }
    }

    /** @return list<array{title: string, slug: string, level: int}> */
    public function parseSections(string $content): array
    {
        preg_match_all('/^(#{2,4})\s+(.+)$/m', $content, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): array => [
            'title' => trim($match[2]),
            'slug' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($match[2])) ?? ''),
            'level' => strlen($match[1]),
        ], $matches);
    }

    public function retrievedAt(string $content): ?string
    {
        return preg_match('/Retrieved (\d{4}-\d{2}-\d{2})\./', $content, $matches) ? $matches[1] : null;
    }

    /**
     * Reflect an installed class/interface/trait into a framework_source + symbol node.
     *
     * @return array{node: GraphNode, source: GraphSource}
     */
    public function addReflectedSymbol(string $class): array
    {
        if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class)) {
            throw new RuntimeException("KNOWLEDGE_SYMBOL_MISSING: {$class} is not installed.");
        }

        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();
        if (! is_string($file) || ! is_file($file)) {
            throw new RuntimeException("KNOWLEDGE_SYMBOL_SOURCE_MISSING: {$class} has no readable source file.");
        }

        $type = match (true) {
            $reflection->isInterface() => 'interface',
            $reflection->isTrait() => 'trait',
            default => 'class',
        };

        $source = $this->addSource(new GraphSource(
            $this->namespace,
            $this->version,
            'framework_source',
            $class,
            $class,
            $file,
            $this->version,
            hash_file('sha256', $file) ?: null,
            ['symbol' => $class, 'installed_version' => $this->version],
        ));

        $node = $this->addNode(
            $type,
            $class,
            $reflection->getShortName(),
            [$source->id()],
            [
                'symbol' => $class,
                'start_line' => $reflection->getStartLine(),
                'end_line' => $reflection->getEndLine(),
                'digest' => $source->digest,
            ],
        );

        return ['node' => $node, 'source' => $source];
    }

    /**
     * @return array{node: GraphNode, source: GraphSource}
     */
    public function addReflectedMethod(string $class, string $method): array
    {
        $symbol = $this->addReflectedSymbol($class);
        if (! method_exists($class, $method)) {
            throw new RuntimeException("KNOWLEDGE_SYMBOL_MISSING: {$class}::{$method} is not installed.");
        }
        $reflection = (new ReflectionClass($class))->getMethod($method);
        $node = $this->addNode(
            'method',
            $class.'::'.$method,
            $method,
            [$symbol['source']->id()],
            [
                'symbol' => $class.'::'.$method,
                'start_line' => $reflection->getStartLine(),
                'end_line' => $reflection->getEndLine(),
            ],
        );

        return ['node' => $node, 'source' => $symbol['source']];
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
