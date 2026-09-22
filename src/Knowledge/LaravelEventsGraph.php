<?php

namespace Sifrious\Molly\Knowledge;

use Illuminate\Events\Dispatcher;
use RuntimeException;
use Sifrious\Molly\PlanningGuide;

final class LaravelEventsGraph
{
    public function __construct(private PlanningGuide $guide) {}

    /**
     * @return array{sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>}
     */
    public function build(string $version): array
    {
        $document = $this->guide->source('laravel-events');
        $builder = new LaravelGraphBuilder('laravel', $version);
        $builder->assertGuideTitleHasLaravelMajor($document['title'], 'events');

        $documentSource = $builder->addSource(new GraphSource(
            'laravel',
            $version,
            'documentation',
            'events',
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
            $key = 'docs:events#'.$section['slug'];
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

        $introduction = $sectionsByKey['docs:events#introduction']
            ?? throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The events guide needs an Introduction section.');
        $events = $builder->addNode('concept', 'events', 'Events', [$documentSource->id()]);
        $builder->addEdge('contains', $versionNode, $events, [$documentSource->id()]);
        $builder->addEdge('documented_in', $events, $introduction, [$documentSource->id()]);

        $dispatcher = $builder->addReflectedSymbol(Dispatcher::class);
        $builder->addEdge('uses', $events, $dispatcher['node'], [$dispatcher['source']->id()]);

        return $builder->finalize();
    }
}
