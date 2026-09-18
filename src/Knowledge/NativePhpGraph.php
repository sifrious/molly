<?php

namespace Sifrious\Molly\Knowledge;

use Illuminate\Support\Str;
use RuntimeException;
use Sifrious\Molly\PlanningGuide;

final class NativePhpGraph
{
    public function __construct(private PlanningGuide $guide) {}

    /**
     * @return array{version: string, sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>}
     */
    public function build(string $track): array
    {
        $config = $this->track($track);
        $document = $this->guide->source($config['source']);
        if (! preg_match($config['title'], $document['title'], $matches)) {
            throw new RuntimeException($config['invalid']);
        }

        $version = $config['prefix'].'-'.$matches[1];
        $sources = [];
        $nodes = [];
        $edges = [];
        $documentSource = new GraphSource(
            'nativephp', $version, 'documentation', $track, $document['title'],
            $document['url'], $document['revision'], $document['sha256'],
            ['path' => $document['path'], 'retrieved_at' => $this->retrievedAt($document['content']), 'track' => $track],
        );
        $sources[$documentSource->id()] = $documentSource;

        $versionNode = $this->node($nodes, $version, 'version', 'nativephp:'.$version, $config['version_label'], [$documentSource->id()]);
        $sections = $this->sections($document['content']);
        if ($sections === []) {
            throw new RuntimeException($config['missing_heading']);
        }

        foreach ($sections as $section) {
            $sectionNode = $this->node(
                $nodes, $version, 'documentation_section', 'docs:'.$track.'#'.$section['slug'],
                $section['title'], [$documentSource->id()], ['heading_level' => $section['level']],
            );
            $this->edge($edges, $version, 'contains', $versionNode, $sectionNode, [$documentSource->id()]);
        }

        $introduction = $nodes[$this->nodeId($version, 'documentation_section', 'docs:'.$track.'#'.$sections[0]['slug'])]
            ?? throw new RuntimeException($config['missing_heading']);
        $product = $this->node($nodes, $version, 'concept', 'nativephp', 'NativePHP', [$documentSource->id()]);
        $surface = $this->node($nodes, $version, 'concept', $track, $config['concept_label'], [$documentSource->id()]);
        $this->edge($edges, $version, 'contains', $versionNode, $product, [$documentSource->id()]);
        $this->edge($edges, $version, 'contains', $versionNode, $surface, [$documentSource->id()]);
        $this->edge($edges, $version, 'documented_in', $product, $introduction, [$documentSource->id()]);
        $this->edge($edges, $version, 'documented_in', $surface, $introduction, [$documentSource->id()]);
        $this->edge($edges, $version, 'related_to', $product, $surface, [$documentSource->id()]);

        ksort($sources);
        ksort($nodes);
        ksort($edges);

        return ['version' => $version, 'sources' => array_values($sources), 'nodes' => array_values($nodes), 'edges' => array_values($edges)];
    }

    /**
     * @return array{source: string, title: string, prefix: string, version_label: string, concept_label: string, invalid: string, missing_heading: string}
     */
    private function track(string $track): array
    {
        return match ($track) {
            'desktop' => [
                'source' => 'nativephp-desktop',
                'title' => '/NativePHP Desktop v(\d+)/',
                'prefix' => 'desktop',
                'version_label' => 'NativePHP Desktop 2',
                'concept_label' => 'Desktop',
                'invalid' => 'KNOWLEDGE_DOC_INVALID: The bundled desktop guide needs a NativePHP Desktop major version in its title.',
                'missing_heading' => 'KNOWLEDGE_DOC_INVALID: The desktop guide needs a heading.',
            ],
            'mobile' => [
                'source' => 'nativephp-mobile',
                'title' => '/NativePHP Mobile v(\d+)/',
                'prefix' => 'mobile',
                'version_label' => 'NativePHP Mobile 4',
                'concept_label' => 'Mobile',
                'invalid' => 'KNOWLEDGE_DOC_INVALID: The bundled mobile guide needs a NativePHP Mobile major version in its title.',
                'missing_heading' => 'KNOWLEDGE_DOC_INVALID: The mobile guide needs a heading.',
            ],
            default => throw new RuntimeException('KNOWLEDGE_TRACK_INVALID: NativePHP tracks are desktop and mobile.'),
        };
    }

    /** @return list<array{title: string, slug: string, level: int}> */
    private function sections(string $content): array
    {
        preg_match_all('/^(#{1,4})\s+(.+)$/m', $content, $matches, PREG_SET_ORDER);

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
        $node = new GraphNode('nativephp', $version, $type, $key, $label, $sourceIds, $metadata);
        $nodes[$node->id()] = $node;

        return $node;
    }

    /**
     * @param  array<string, GraphEdge>  $edges
     * @param  list<string>  $sourceIds
     */
    private function edge(array &$edges, string $version, string $relation, GraphNode $from, GraphNode $to, array $sourceIds): void
    {
        $edge = new GraphEdge('nativephp', $version, $relation, $from->id(), $to->id(), $sourceIds);
        $edges[$edge->id()] = $edge;
    }

    private function nodeId(string $version, string $type, string $key): string
    {
        return (new GraphNode('nativephp', $version, $type, $key, '', []))->id();
    }

    private function retrievedAt(string $content): ?string
    {
        return preg_match('/Retrieved (\d{4}-\d{2}-\d{2})\./', $content, $matches) ? $matches[1] : null;
    }
}
