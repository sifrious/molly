<?php

namespace Sifrious\Molly\Knowledge;

use Illuminate\Support\Str;
use RuntimeException;
use Sifrious\Molly\PlanningGuide;

final class TarpitGraph
{
    public function __construct(private PlanningGuide $guide) {}

    public function version(): string
    {
        $document = $this->guide->source('mary-tarpit');
        $retrieved = $this->retrievedAt($document['content'])
            ?? throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The tarpit notes need a retrieval date.');

        return 'notes-'.$retrieved;
    }

    /**
     * @return array{version: string, sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>}
     */
    public function build(): array
    {
        $document = $this->guide->source('mary-tarpit');
        if (! str_contains($document['title'], 'Cleverness is a loan')) {
            throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The bundled tarpit notes need the talk title.');
        }

        $version = $this->version();
        $sources = [];
        $nodes = [];
        $edges = [];
        $documentSource = new GraphSource(
            'tarpit', $version, 'documentation', 'mary-tarpit', $document['title'],
            $document['url'], $document['revision'], $document['sha256'],
            ['path' => $document['path'], 'retrieved_at' => substr($version, strlen('notes-'))],
        );
        $sources[$documentSource->id()] = $documentSource;

        $versionNode = $this->node($nodes, $version, 'version', 'tarpit:'.$version, 'Tarpit notes '.substr($version, strlen('notes-')), [$documentSource->id()]);
        $sections = $this->sections($document['content']);
        if ($sections === []) {
            throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The tarpit notes need headings.');
        }

        foreach ($sections as $section) {
            $sectionNode = $this->node(
                $nodes, $version, 'documentation_section', 'docs:mary-tarpit#'.$section['slug'],
                $section['title'], [$documentSource->id()], ['heading_level' => $section['level']],
            );
            $this->edge($edges, $version, 'contains', $versionNode, $sectionNode, [$documentSource->id()]);
        }

        $complexitySection = $this->section($nodes, $version, 'complexity-divided');
        $tarpitSection = $this->section($nodes, $version, 'the-tar-pit');
        $layersSection = $this->section($nodes, $version, 'the-layers');
        $tarpit = $this->node($nodes, $version, 'concept', 'tarpit', 'Tarpit', [$documentSource->id()]);
        $complexity = $this->node($nodes, $version, 'concept', 'complexity', 'Complexity', [$documentSource->id()]);
        $essence = $this->node($nodes, $version, 'concept', 'essence', 'Essence', [$documentSource->id()]);
        $accident = $this->node($nodes, $version, 'concept', 'accident', 'Accident', [$documentSource->id()]);
        $cleverness = $this->node($nodes, $version, 'concept', 'cleverness', 'Cleverness', [$documentSource->id()]);
        $layers = $this->node($nodes, $version, 'concept', 'layers', 'Layers', [$documentSource->id()]);
        foreach ([$tarpit, $complexity, $essence, $accident, $cleverness, $layers] as $concept) {
            $this->edge($edges, $version, 'contains', $versionNode, $concept, [$documentSource->id()]);
        }
        $this->edge($edges, $version, 'documented_in', $complexity, $complexitySection, [$documentSource->id()]);
        $this->edge($edges, $version, 'documented_in', $essence, $complexitySection, [$documentSource->id()]);
        $this->edge($edges, $version, 'documented_in', $accident, $complexitySection, [$documentSource->id()]);
        $this->edge($edges, $version, 'documented_in', $cleverness, $complexitySection, [$documentSource->id()]);
        $this->edge($edges, $version, 'documented_in', $tarpit, $tarpitSection, [$documentSource->id()]);
        $this->edge($edges, $version, 'documented_in', $layers, $layersSection, [$documentSource->id()]);
        $this->edge($edges, $version, 'related_to', $complexity, $essence, [$documentSource->id()]);
        $this->edge($edges, $version, 'related_to', $complexity, $accident, [$documentSource->id()]);
        $this->edge($edges, $version, 'related_to', $complexity, $cleverness, [$documentSource->id()]);
        $this->edge($edges, $version, 'related_to', $tarpit, $complexity, [$documentSource->id()]);
        $this->edge($edges, $version, 'related_to', $layers, $tarpit, [$documentSource->id()]);

        ksort($sources);
        ksort($nodes);
        ksort($edges);

        return ['version' => $version, 'sources' => array_values($sources), 'nodes' => array_values($nodes), 'edges' => array_values($edges)];
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
     */
    private function section(array $nodes, string $version, string $slug): GraphNode
    {
        return $nodes[$this->nodeId($version, 'documentation_section', 'docs:mary-tarpit#'.$slug)]
            ?? throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The tarpit notes need the '.$slug.' section.');
    }

    /**
     * @param  array<string, GraphNode>  $nodes
     * @param  list<string>  $sourceIds
     * @param  array<string, mixed>  $metadata
     */
    private function node(array &$nodes, string $version, string $type, string $key, string $label, array $sourceIds, array $metadata = []): GraphNode
    {
        $node = new GraphNode('tarpit', $version, $type, $key, $label, $sourceIds, $metadata);
        $nodes[$node->id()] = $node;

        return $node;
    }

    /**
     * @param  array<string, GraphEdge>  $edges
     * @param  list<string>  $sourceIds
     */
    private function edge(array &$edges, string $version, string $relation, GraphNode $from, GraphNode $to, array $sourceIds): void
    {
        $edge = new GraphEdge('tarpit', $version, $relation, $from->id(), $to->id(), $sourceIds);
        $edges[$edge->id()] = $edge;
    }

    private function nodeId(string $version, string $type, string $key): string
    {
        return (new GraphNode('tarpit', $version, $type, $key, '', []))->id();
    }

    private function retrievedAt(string $content): ?string
    {
        return preg_match('/Retrieved (\d{4}-\d{2}-\d{2})\./', $content, $matches) ? $matches[1] : null;
    }
}
