<?php

namespace Sifrious\Molly\Knowledge;

use RuntimeException;
use Sifrious\Molly\PlanningGuide;

final class LaravelTestingGraph
{
    public function __construct(private PlanningGuide $guide) {}

    /**
     * @return array{sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>}
     */
    public function build(string $version): array
    {
        $document = $this->guide->source('laravel-testing');
        $builder = new LaravelGraphBuilder('laravel', $version);
        $builder->assertGuideTitleHasLaravelMajor($document['title'], 'testing');

        $documentSource = $builder->addSource(new GraphSource(
            'laravel',
            $version,
            'documentation',
            'testing',
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
            $key = 'docs:testing#'.$section['slug'];
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

        $introduction = $sectionsByKey['docs:testing#introduction']
            ?? throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The testing guide needs an Introduction section.');
        $pest = $builder->addNode('concept', 'pest', 'Pest', [$documentSource->id()]);
        $phpunit = $builder->addNode('concept', 'phpunit', 'PHPUnit', [$documentSource->id()]);
        $feature = $builder->addNode('configuration', 'tests/Feature', 'tests/Feature', [$documentSource->id()]);
        foreach ([$pest, $phpunit, $feature] as $concept) {
            $builder->addEdge('contains', $versionNode, $concept, [$documentSource->id()]);
            $builder->addEdge('documented_in', $concept, $introduction, [$documentSource->id()]);
        }
        $builder->addEdge('related_to', $pest, $phpunit, [$documentSource->id()]);
        $builder->addEdge('tested_by', $feature, $pest, [$documentSource->id()]);

        $testCase = $builder->addReflectedSymbol('Illuminate\Foundation\Testing\TestCase');
        $builder->addEdge('uses', $phpunit, $testCase['node'], [$testCase['source']->id()]);
        $builder->addEdge('uses', $pest, $testCase['node'], [$testCase['source']->id()]);

        return $builder->finalize();
    }
}
