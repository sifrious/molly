<?php

namespace Sifrious\Molly\Knowledge;

use Illuminate\Container\Container;
use RuntimeException;
use Sifrious\Molly\PlanningGuide;

final class LaravelContainerGraph
{
    public function __construct(private PlanningGuide $guide) {}

    /**
     * @return array{sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>}
     */
    public function build(string $version): array
    {
        $document = $this->guide->source('laravel-container');
        $builder = new LaravelGraphBuilder('laravel', $version);
        $builder->assertGuideTitleHasLaravelMajor($document['title'], 'container');

        $documentSource = $builder->addSource(new GraphSource(
            'laravel',
            $version,
            'documentation',
            'container',
            $document['title'],
            $document['url'],
            $document['revision'],
            $document['sha256'],
            ['path' => $document['path'], 'retrieved_at' => $builder->retrievedAt($document['content'])],
        ));

        $versionNode = $builder->addNode('version', 'laravel:'.$version, 'Laravel '.$version, [$documentSource->id()]);
        /** @var array<string, GraphNode> $sectionsByKey */
        $sectionsByKey = [];
        foreach ($builder->parseSections($document['content']) as $section) {
            $key = 'docs:container#'.$section['slug'];
            $sectionNode = $builder->addNode(
                'documentation_section',
                $key,
                $section['title'],
                [$documentSource->id()],
                ['heading_level' => $section['level']],
            );
            $builder->addEdge('contains', $versionNode, $sectionNode, [$documentSource->id()]);
            $sectionsByKey[$key] = $sectionNode;
        }

        $required = $sectionsByKey['docs:container#zero-configuration-resolution']
            ?? throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The container guide needs a Zero Configuration Resolution section.');
        $concept = $builder->addNode('concept', 'container', 'Container', [$documentSource->id()]);
        $builder->addEdge('contains', $versionNode, $concept, [$documentSource->id()]);
        $builder->addEdge('documented_in', $concept, $required, [$documentSource->id()]);

        $symbol = $builder->addReflectedSymbol(Container::class);
        $builder->addEdge('uses', $concept, $symbol['node'], [$symbol['source']->id()]);

        return $builder->finalize();
    }
}
