<?php

namespace Sifrious\Molly\Knowledge;

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
        $builder = new LaravelGraphBuilder('laravel', $version);
        $builder->assertGuideTitleHasLaravelMajor($document['title'], 'queue');

        $documentSource = $builder->addSource(new GraphSource(
            'laravel',
            $version,
            'documentation',
            'queues',
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
            $key = 'docs:queues#'.$section['slug'];
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

        $introduction = $sectionsByKey['docs:queues#introduction']
            ?? throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The queue guide needs an Introduction section.');
        $connectionsSection = $sectionsByKey['docs:queues#connections-vs-queues']
            ?? throw new RuntimeException('KNOWLEDGE_DOC_INVALID: The queue guide needs a Connections vs. Queues section.');

        $queue = $builder->addNode('concept', 'queue', 'Queue', [$documentSource->id()]);
        $job = $builder->addNode('concept', 'job', 'Job', [$documentSource->id()]);
        $connection = $builder->addNode('concept', 'connection', 'Connection', [$documentSource->id()]);
        $worker = $builder->addNode('concept', 'worker', 'Worker', [$documentSource->id()]);
        $configuration = $builder->addNode('configuration', 'config/queue.php', 'config/queue.php', [$documentSource->id()]);
        foreach ([$queue, $job, $connection, $worker, $configuration] as $concept) {
            $builder->addEdge('contains', $versionNode, $concept, [$documentSource->id()]);
        }
        foreach ([$queue, $job, $worker, $configuration] as $concept) {
            $builder->addEdge('documented_in', $concept, $introduction, [$documentSource->id()]);
        }
        $builder->addEdge('documented_in', $connection, $connectionsSection, [$documentSource->id()]);
        $builder->addEdge('configured_by', $queue, $configuration, [$documentSource->id()]);
        $builder->addEdge('related_to', $queue, $job, [$documentSource->id()]);
        $builder->addEdge('related_to', $queue, $connection, [$documentSource->id()]);
        $builder->addEdge('uses', $worker, $queue, [$documentSource->id()]);

        $shouldQueue = $builder->addReflectedSymbol('Illuminate\Contracts\Queue\ShouldQueue');
        $attempts = $builder->addReflectedMethod('Illuminate\Queue\InteractsWithQueue', 'attempts');
        $release = $builder->addReflectedMethod('Illuminate\Queue\InteractsWithQueue', 'release');
        $middleware = $builder->addReflectedSymbol('Illuminate\Queue\Middleware\WithoutOverlapping');
        $expireAfter = $builder->addReflectedMethod('Illuminate\Queue\Middleware\WithoutOverlapping', 'expireAfter');
        $queueFake = $builder->addReflectedSymbol('Illuminate\Support\Testing\Fakes\QueueFake');
        $assertPushed = $builder->addReflectedMethod('Illuminate\Support\Testing\Fakes\QueueFake', 'assertPushed');

        $retry = $builder->addNode('concept', 'retry', 'Retry', [$attempts['source']->id()]);
        $middlewareConcept = $builder->addNode('concept', 'middleware', 'Queue middleware', [$middleware['source']->id()]);
        $testing = $builder->addNode('concept', 'testing', 'Queue testing', [$queueFake['source']->id()]);

        $builder->addEdge('contains', $versionNode, $retry, [$attempts['source']->id()]);
        $builder->addEdge('contains', $versionNode, $middlewareConcept, [$middleware['source']->id()]);
        $builder->addEdge('contains', $versionNode, $testing, [$queueFake['source']->id()]);
        $builder->addEdge('implements', $job, $shouldQueue['node'], [$shouldQueue['source']->id()]);
        $builder->addEdge('uses', $job, $retry, [$attempts['source']->id()]);
        $builder->addEdge('uses', $retry, $attempts['node'], [$attempts['source']->id()]);
        $builder->addEdge('uses', $retry, $release['node'], [$release['source']->id()]);
        $builder->addEdge('uses', $job, $middlewareConcept, [$middleware['source']->id()]);
        $builder->addEdge('example_of', $middleware['node'], $middlewareConcept, [$middleware['source']->id()]);
        $builder->addEdge('belongs_to', $expireAfter['node'], $middleware['node'], [$expireAfter['source']->id()]);
        $builder->addEdge('tested_by', $job, $testing, [$queueFake['source']->id()]);
        $builder->addEdge('example_of', $queueFake['node'], $testing, [$queueFake['source']->id()]);
        $builder->addEdge('uses', $testing, $assertPushed['node'], [$assertPushed['source']->id()]);
        $builder->addEdge('belongs_to', $assertPushed['node'], $queueFake['node'], [$assertPushed['source']->id()]);

        return $builder->finalize();
    }
}
