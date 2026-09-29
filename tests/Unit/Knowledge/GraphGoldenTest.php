<?php

/*
 * Golden snapshots of the knowledge graphs Molly builds from bundled documentation and
 * reflected framework source. Each snapshot lists every source, node, and edge with its
 * type, endpoints, and provenance, so a wrong edge fails the suite as one changed line.
 *
 * RC10 negative control M11.4 changed one queue graph edge and all 110 knowledge tests
 * still passed:
 *
 *   -        $builder->addEdge('belongs_to', $expireAfter['node'], $middleware['node'], [$expireAfter['source']->id()]);
 *   +        $builder->addEdge('belongs_to', $expireAfter['node'], $queueFake['node'], [$expireAfter['source']->id()]);
 *
 * With that change, "matches the golden laravel-queue graph" and "attaches every reflected
 * method to the class it was reflected from" fail with:
 *
 *   Wrong belongs_to edge: method:Illuminate\Queue\Middleware\WithoutOverlapping::expireAfter must point at
 *   class:Illuminate\Queue\Middleware\WithoutOverlapping, but the graph points it at
 *   class:Illuminate\Support\Testing\Fakes\QueueFake.
 *
 * Rewrite the golden files only with MOLLY_UPDATE_GRAPH_GOLDENS=1 (docs/contributing.md).
 */

use Sifrious\Molly\Knowledge\LaravelContainerGraph;
use Sifrious\Molly\Knowledge\LaravelEloquentGraph;
use Sifrious\Molly\Knowledge\LaravelEventsGraph;
use Sifrious\Molly\Knowledge\LaravelQueueGraph;
use Sifrious\Molly\Knowledge\LaravelRoutingGraph;
use Sifrious\Molly\Knowledge\LaravelTestingGraph;
use Sifrious\Molly\Knowledge\LaravelValidationGraph;
use Sifrious\Molly\Knowledge\NativePhpGraph;
use Sifrious\Molly\Knowledge\TarpitGraph;
use Sifrious\Molly\Tests\Support\GraphGolden;

/**
 * The Laravel major of the bundled guides, which the golden files pin. The builders reflect
 * whichever laravel/framework release is installed, so the files leave out the values that
 * change between releases.
 */
const GOLDEN_LARAVEL_VERSION = '13';

dataset('laravel graph builders', [
    'laravel-queue' => [LaravelQueueGraph::class],
    'laravel-routing' => [LaravelRoutingGraph::class],
    'laravel-testing' => [LaravelTestingGraph::class],
    'laravel-validation' => [LaravelValidationGraph::class],
    'laravel-container' => [LaravelContainerGraph::class],
    'laravel-eloquent' => [LaravelEloquentGraph::class],
    'laravel-events' => [LaravelEventsGraph::class],
]);

function goldenLaravelGraph(string $builder): array
{
    return GraphGolden::fromRecords('laravel', GOLDEN_LARAVEL_VERSION, app($builder)->build(GOLDEN_LARAVEL_VERSION));
}

it('matches the golden graph for each Laravel guide', function (string $builder) {
    $name = 'laravel-'.strtolower(substr(class_basename($builder), strlen('Laravel'), -strlen('Graph')));

    GraphGolden::assertMatchesFile(goldenLaravelGraph($builder), $name);
})->with('laravel graph builders')->group('graph-golden');

it('attaches every reflected method to the class it was reflected from', function (string $builder) {
    $graph = goldenLaravelGraph($builder);
    $types = array_column($graph['nodes'], 'ref');
    expect($graph['edges'])->not->toBeEmpty();

    foreach ($graph['edges'] as $edge) {
        if ($edge['relation'] !== 'belongs_to' || ! str_starts_with($edge['from'], 'method:')) {
            continue;
        }
        $class = explode('::', substr($edge['from'], strlen('method:')), 2)[0];
        $owner = array_values(array_filter($types, fn (string $ref): bool => in_array($ref, ['class:'.$class, 'interface:'.$class, 'trait:'.$class], true)));

        expect($owner)->toHaveCount(1, "{$edge['from']} has no class node for {$class}");
        GraphGolden::assertEdges($graph, $edge['from'], 'belongs_to', $owner);
        expect($edge['sources'])->toBe(['framework_source:'.$class], "{$edge['from']} belongs_to must cite the reflected source of {$class}");
    }
})->with('laravel graph builders');

it('keeps the queue graph relationships the queue guide and reflected source state', function () {
    $graph = goldenLaravelGraph(LaravelQueueGraph::class);
    $middleware = 'class:Illuminate\Queue\Middleware\WithoutOverlapping';
    $fake = 'class:Illuminate\Support\Testing\Fakes\QueueFake';

    GraphGolden::assertEdges($graph, 'method:Illuminate\Queue\Middleware\WithoutOverlapping::expireAfter', 'belongs_to', [$middleware]);
    GraphGolden::assertEdges($graph, 'method:Illuminate\Support\Testing\Fakes\QueueFake::assertPushed', 'belongs_to', [$fake]);
    GraphGolden::assertEdges($graph, $middleware, 'example_of', ['concept:middleware']);
    GraphGolden::assertEdges($graph, $fake, 'example_of', ['concept:testing']);
    GraphGolden::assertEdges($graph, 'concept:job', 'implements', ['interface:Illuminate\Contracts\Queue\ShouldQueue']);
    GraphGolden::assertEdges($graph, 'concept:job', 'uses', ['concept:middleware', 'concept:retry']);
    GraphGolden::assertEdges($graph, 'concept:job', 'tested_by', ['concept:testing']);
    GraphGolden::assertEdges($graph, 'concept:retry', 'uses', [
        'method:Illuminate\Queue\InteractsWithQueue::attempts',
        'method:Illuminate\Queue\InteractsWithQueue::release',
    ]);
    GraphGolden::assertEdges($graph, 'concept:testing', 'uses', ['method:Illuminate\Support\Testing\Fakes\QueueFake::assertPushed']);
    GraphGolden::assertEdges($graph, 'concept:queue', 'configured_by', ['configuration:config/queue.php']);
    GraphGolden::assertEdges($graph, 'concept:worker', 'uses', ['concept:queue']);
    GraphGolden::assertEdges($graph, 'concept:connection', 'documented_in', ['documentation_section:docs:queues#connections-vs-queues']);
});

it('matches the golden graph for each NativePHP track', function (string $track) {
    $built = app(NativePhpGraph::class)->build($track);

    GraphGolden::assertMatchesFile(GraphGolden::fromRecords('nativephp', $built['version'], $built), 'nativephp-'.$track);
})->with(['desktop', 'mobile'])->group('graph-golden');

it('matches the golden Tarpit notes graph', function () {
    $built = app(TarpitGraph::class)->build();
    $graph = GraphGolden::fromRecords('tarpit', $built['version'], $built);

    GraphGolden::assertEdges($graph, 'concept:complexity', 'related_to', ['concept:accident', 'concept:cleverness', 'concept:essence']);
    GraphGolden::assertEdges($graph, 'concept:tarpit', 'documented_in', ['documentation_section:docs:mary-tarpit#the-tar-pit']);
    GraphGolden::assertMatchesFile($graph, 'tarpit');
})->group('graph-golden');
