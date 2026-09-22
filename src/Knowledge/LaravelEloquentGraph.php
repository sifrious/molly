<?php

namespace Sifrious\Molly\Knowledge;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Sifrious\Molly\PlanningGuide;

final class LaravelEloquentGraph
{
    public function __construct(private PlanningGuide $guide) {}

    /**
     * @return array{sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>}
     */
    public function build(string $version): array
    {
        $document = $this->guide->source('laravel-eloquent');
        $builder = new LaravelGraphBuilder('laravel', $version);
        $builder->assertGuideTitleHasLaravelMajor($document['title'], 'eloquent');

        $documentSource = $builder->addSource(new GraphSource(
            'laravel',
            $version,
            'documentation',
            'eloquent',
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
            $key = 'docs:eloquent#'.$section['slug'];
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

        $required = $sectionsByKey['docs:eloquent#introduction']
            ?? throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The eloquent guide needs an Introduction section.');
        $concept = $builder->addNode('concept', 'eloquent', 'Eloquent', [$documentSource->id()]);
        $builder->addEdge('contains', $versionNode, $concept, [$documentSource->id()]);
        $builder->addEdge('documented_in', $concept, $required, [$documentSource->id()]);

        $symbol = $builder->addReflectedSymbol(Model::class);
        $builder->addEdge('uses', $concept, $symbol['node'], [$symbol['source']->id()]);

        return $builder->finalize();
    }
}
