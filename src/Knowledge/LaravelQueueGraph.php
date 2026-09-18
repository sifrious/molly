<?php

namespace Sifrious\Molly\Knowledge;

use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;
use Sifrious\Molly\PlanningGuide;

final class LaravelQueueGraph
{
    public function __construct(private PlanningGuide $guide) {}

    /**
     * @return array{sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>}
     */
    public function build(string $version): array
    {
        $document = $this->guide->source('laravel-queues');
        if (! preg_match('/Laravel \\d+ /', $document['title'])) {
            throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The bundled queue guide needs a Laravel major version in its title.');
        }

        $sources = [];
        $nodes = [];
        $edges = [];
        $documentSource = new GraphSource(
            'laravel', $version, 'documentation', 'queues', $document['title'],
            $document['url'], $document['revision'], $document['sha256'],
            ['path' => $document['path'], 'retrieved_at' => $this->retrievedAt($document['content'])],
        );
        $sources[$documentSource->id()] = $documentSource;

        $versionNode = $this->node($nodes, $version, 'version', 'laravel:'.$version, 'Laravel '.$version, [$documentSource->id()]);
        $sections = $this->sections($document['content']);
        foreach ($sections as $section) {
            $sectionNode = $this->node(
                $nodes, $version, 'documentation_section', 'docs:queues#'.$section['slug'],
                $section['title'], [$documentSource->id()], ['heading_level' => $section['level']],
            );
            $this->edge($edges, $version, 'contains', $versionNode, $sectionNode, [$documentSource->id()]);
        }

        $introduction = $nodes[$this->nodeId($version, 'documentation_section', 'docs:queues#introduction')]
            ?? throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The queue guide needs an Introduction section.');
        $connectionsSection = $nodes[$this->nodeId($version, 'documentation_section', 'docs:queues#connections-vs-queues')]
            ?? throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The queue guide needs a Connections vs. Queues section.');

        $queue = $this->node($nodes, $version, 'concept', 'queue', 'Queue', [$documentSource->id()]);
        $job = $this->node($nodes, $version, 'concept', 'job', 'Job', [$documentSource->id()]);
        $connection = $this->node($nodes, $version, 'concept', 'connection', 'Connection', [$documentSource->id()]);
        $worker = $this->node($nodes, $version, 'concept', 'worker', 'Worker', [$documentSource->id()]);
        $configuration = $this->node($nodes, $version, 'configuration', 'config/queue.php', 'config/queue.php', [$documentSource->id()]);
        foreach ([$queue, $job, $connection, $worker, $configuration] as $concept) {
            $this->edge($edges, $version, 'contains', $versionNode, $concept, [$documentSource->id()]);
        }
        foreach ([$queue, $job, $worker, $configuration] as $concept) {
            $this->edge($edges, $version, 'documented_in', $concept, $introduction, [$documentSource->id()]);
        }
        $this->edge($edges, $version, 'documented_in', $connection, $connectionsSection, [$documentSource->id()]);
        $this->edge($edges, $version, 'configured_by', $queue, $configuration, [$documentSource->id()]);
        $this->edge($edges, $version, 'related_to', $queue, $job, [$documentSource->id()]);
        $this->edge($edges, $version, 'related_to', $queue, $connection, [$documentSource->id()]);
        $this->edge($edges, $version, 'uses', $worker, $queue, [$documentSource->id()]);

        $shouldQueue = $this->symbol($sources, $nodes, $version, 'Illuminate\Contracts\Queue\ShouldQueue');
        $attempts = $this->symbol($sources, $nodes, $version, 'Illuminate\Queue\InteractsWithQueue', 'attempts');
        $release = $this->symbol($sources, $nodes, $version, 'Illuminate\Queue\InteractsWithQueue', 'release');
        $middleware = $this->symbol($sources, $nodes, $version, 'Illuminate\Queue\Middleware\WithoutOverlapping');
        $expireAfter = $this->symbol($sources, $nodes, $version, 'Illuminate\Queue\Middleware\WithoutOverlapping', 'expireAfter');
        $queueFake = $this->symbol($sources, $nodes, $version, 'Illuminate\Support\Testing\Fakes\QueueFake');
        $assertPushed = $this->symbol($sources, $nodes, $version, 'Illuminate\Support\Testing\Fakes\QueueFake', 'assertPushed');

        $retrySource = $sources[$attempts['source']];
        $middlewareSource = $sources[$middleware['source']];
        $testingSource = $sources[$queueFake['source']];
        $retry = $this->node($nodes, $version, 'concept', 'retry', 'Retry', [$retrySource->id()]);
        $middlewareConcept = $this->node($nodes, $version, 'concept', 'middleware', 'Queue middleware', [$middlewareSource->id()]);
        $testing = $this->node($nodes, $version, 'concept', 'testing', 'Queue testing', [$testingSource->id()]);

        $this->edge($edges, $version, 'contains', $versionNode, $retry, [$retrySource->id()]);
        $this->edge($edges, $version, 'contains', $versionNode, $middlewareConcept, [$middlewareSource->id()]);
        $this->edge($edges, $version, 'contains', $versionNode, $testing, [$testingSource->id()]);
        $this->edge($edges, $version, 'implements', $job, $shouldQueue['node'], [$shouldQueue['source']]);
        $this->edge($edges, $version, 'uses', $job, $retry, [$retrySource->id()]);
        $this->edge($edges, $version, 'uses', $retry, $attempts['node'], [$attempts['source']]);
        $this->edge($edges, $version, 'uses', $retry, $release['node'], [$release['source']]);
        $this->edge($edges, $version, 'uses', $job, $middlewareConcept, [$middlewareSource->id()]);
        $this->edge($edges, $version, 'example_of', $middleware['node'], $middlewareConcept, [$middleware['source']]);
        $this->edge($edges, $version, 'belongs_to', $expireAfter['node'], $middleware['node'], [$expireAfter['source']]);
        $this->edge($edges, $version, 'tested_by', $job, $testing, [$testingSource->id()]);
        $this->edge($edges, $version, 'example_of', $queueFake['node'], $testing, [$queueFake['source']]);
        $this->edge($edges, $version, 'uses', $testing, $assertPushed['node'], [$assertPushed['source']]);
        $this->edge($edges, $version, 'belongs_to', $assertPushed['node'], $queueFake['node'], [$assertPushed['source']]);

        ksort($sources);
        ksort($nodes);
        ksort($edges);

        return ['sources' => array_values($sources), 'nodes' => array_values($nodes), 'edges' => array_values($edges)];
    }

    /**
     * @param  array<string, GraphSource>  $sources
     * @param  array<string, GraphNode>  $nodes
     * @return array{node: GraphNode, source: string}
     */
    private function symbol(array &$sources, array &$nodes, string $version, string $class, ?string $method = null): array
    {
        if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class)) {
            throw new RuntimeException("KNOWLEDGE_SYMBOL_MISSING: {$class} is not installed.");
        }

        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();
        if (! is_string($file) || ! is_file($file)) {
            throw new RuntimeException("KNOWLEDGE_SYMBOL_SOURCE_MISSING: {$class} has no readable source file.");
        }
        $source = new GraphSource(
            'laravel', $version, 'framework_source', $class, $class,
            $this->relativePath($file), $version, hash_file('sha256', $file),
            ['symbol' => $class, 'installed_version' => $version],
        );
        $sources[$source->id()] = $source;

        if ($method !== null) {
            if (! $reflection->hasMethod($method)) {
                throw new RuntimeException("KNOWLEDGE_SYMBOL_MISSING: {$class}::{$method} is not installed.");
            }
            $reflectedMethod = $reflection->getMethod($method);
            $node = $this->node(
                $nodes, $version, 'method', $class.'::'.$method, class_basename($class).'::'.$method,
                [$source->id()], ['symbol' => $class.'::'.$method, 'start_line' => $reflectedMethod->getStartLine(), 'end_line' => $reflectedMethod->getEndLine()],
            );
        } else {
            $type = $reflection->isInterface() ? 'interface' : ($reflection->isTrait() ? 'trait' : 'class');
            $node = $this->node(
                $nodes, $version, $type, $class, class_basename($class), [$source->id()],
                ['symbol' => $class, 'start_line' => $reflection->getStartLine(), 'end_line' => $reflection->getEndLine()],
            );
        }

        return ['node' => $node, 'source' => $source->id()];
    }

    /** @return list<array{title: string, slug: string, level: int}> */
    private function sections(string $content): array
    {
        preg_match_all('/^(#{2,4})\s+(.+)$/m', $content, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match): array => [
            'title' => trim($match[2]),
            'slug' => Str::slug(trim($match[2])),
            'level' => strlen($match[1]),
        ], $matches);
    }

    /**
     * @param  array<string, GraphNode>  $nodes
     * @param  list<string>  $sourceIds
     * @param  array<string, mixed>  $metadata
     */
    private function node(array &$nodes, string $version, string $type, string $key, string $label, array $sourceIds, array $metadata = []): GraphNode
    {
        $node = new GraphNode('laravel', $version, $type, $key, $label, $sourceIds, $metadata);
        $nodes[$node->id()] = $node;

        return $node;
    }

    /**
     * @param  array<string, GraphEdge>  $edges
     * @param  list<string>  $sourceIds
     */
    private function edge(array &$edges, string $version, string $relation, GraphNode $from, GraphNode $to, array $sourceIds): void
    {
        $edge = new GraphEdge('laravel', $version, $relation, $from->id(), $to->id(), $sourceIds);
        $edges[$edge->id()] = $edge;
    }

    private function nodeId(string $version, string $type, string $key): string
    {
        return (new GraphNode('laravel', $version, $type, $key, '', []))->id();
    }

    private function relativePath(string $path): string
    {
        $base = rtrim((string) realpath(base_path()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($base))) : $path;
    }

    private function retrievedAt(string $content): ?string
    {
        return preg_match('/Retrieved (\d{4}-\d{2}-\d{2})\./', $content, $matches) ? $matches[1] : null;
    }
}
