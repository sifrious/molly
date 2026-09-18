<?php

namespace Sifrious\Molly\Knowledge;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;
use Sifrious\Molly\PlanningGuide;

final class LaravelRoutingGraph
{
    public function __construct(private PlanningGuide $guide) {}

    /**
     * @return array{sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>}
     */
    public function build(string $version): array
    {
        $document = $this->guide->source('laravel-routing');
        if (! str_contains($document['title'], 'Laravel '.$version.' ')) {
            throw new RuntimeException("KNOWLEDGE_DOC_VERSION_MISMATCH: The bundled routing guide is not for Laravel {$version}.");
        }

        $sources = [];
        $nodes = [];
        $edges = [];
        $documentSource = new GraphSource(
            'laravel', $version, 'documentation', 'routing', $document['title'],
            $document['url'], $document['revision'], $document['sha256'],
            ['path' => $document['path'], 'retrieved_at' => $this->retrievedAt($document['content'])],
        );
        $sources[$documentSource->id()] = $documentSource;

        $versionNode = $this->node($nodes, $version, 'version', 'laravel:'.$version, 'Laravel '.$version, [$documentSource->id()]);
        $sections = $this->sections($document['content']);
        foreach ($sections as $section) {
            $sectionNode = $this->node(
                $nodes, $version, 'documentation_section', 'docs:routing#'.$section['slug'],
                $section['title'], [$documentSource->id()], ['heading_level' => $section['level']],
            );
            $this->edge($edges, $version, 'contains', $versionNode, $sectionNode, [$documentSource->id()]);
        }

        $basic = $nodes[$this->nodeId($version, 'documentation_section', 'docs:routing#basic-routing')]
            ?? throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The routing guide needs a Basic Routing section.');
        $route = $this->node($nodes, $version, 'concept', 'route', 'Route', [$documentSource->id()]);
        $web = $this->node($nodes, $version, 'configuration', 'routes/web.php', 'routes/web.php', [$documentSource->id()]);
        $this->edge($edges, $version, 'contains', $versionNode, $route, [$documentSource->id()]);
        $this->edge($edges, $version, 'contains', $versionNode, $web, [$documentSource->id()]);
        $this->edge($edges, $version, 'documented_in', $route, $basic, [$documentSource->id()]);
        $this->edge($edges, $version, 'configured_by', $route, $web, [$documentSource->id()]);

        $facade = $this->symbol($sources, $nodes, $version, Route::class);
        $this->edge($edges, $version, 'uses', $route, $facade['node'], [$facade['source']]);
        $this->edge($edges, $version, 'uses', $web, $facade['node'], [$facade['source']]);

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
    private function symbol(array &$sources, array &$nodes, string $version, string $class): array
    {
        if (! class_exists($class)) {
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
        $node = $this->node(
            $nodes, $version, 'class', $class, class_basename($class), [$source->id()],
            ['symbol' => $class, 'start_line' => $reflection->getStartLine(), 'end_line' => $reflection->getEndLine()],
        );

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
