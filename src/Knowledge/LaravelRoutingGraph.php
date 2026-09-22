<?php

namespace Sifrious\Molly\Knowledge;

use Illuminate\Support\Facades\Route;
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
        $builder = new LaravelGraphBuilder('laravel', $version);
        $builder->assertGuideTitleHasLaravelMajor($document['title'], 'routing');

        $documentSource = $builder->addSource(new GraphSource(
            'laravel',
            $version,
            'documentation',
            'routing',
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
            $key = 'docs:routing#'.$section['slug'];
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

        $basic = $sectionsByKey['docs:routing#basic-routing']
            ?? throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The routing guide needs a Basic Routing section.');
        $route = $builder->addNode('concept', 'route', 'Route', [$documentSource->id()]);
        $web = $builder->addNode('configuration', 'routes/web.php', 'routes/web.php', [$documentSource->id()]);
        $builder->addEdge('contains', $versionNode, $route, [$documentSource->id()]);
        $builder->addEdge('contains', $versionNode, $web, [$documentSource->id()]);
        $builder->addEdge('documented_in', $route, $basic, [$documentSource->id()]);
        $builder->addEdge('configured_by', $route, $web, [$documentSource->id()]);

        $facade = $builder->addReflectedSymbol(Route::class);
        $builder->addEdge('uses', $route, $facade['node'], [$facade['source']->id()]);
        $builder->addEdge('uses', $web, $facade['node'], [$facade['source']->id()]);

        return $builder->finalize();
    }
}
