<?php

use Sifrious\Molly\GraphDelta\Freshness;
use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphEdge;
use Sifrious\Molly\Knowledge\GraphNode;
use Sifrious\Molly\Knowledge\GraphSchema;
use Sifrious\Molly\Knowledge\GraphSource;
use Sifrious\Molly\Knowledge\LearningCitation;
use Sifrious\Molly\Knowledge\LearningPathBuilder;
use Sifrious\Molly\Knowledge\LearningPathView;
use Sifrious\Molly\Knowledge\LearningStepView;

function learningGraph(?string $database = null): Graph
{
    return new Graph(new GraphSchema, $database ?? tempnam(sys_get_temp_dir(), 'molly_learn_') . '.sqlite');
}

function seedRoutingGraph(Graph $graph): void
{
    $source = new GraphSource('laravel', '12', 'documentation', 'routing', 'Laravel 12 Routing', '/docs/routing.md', '12', 'abc123');
    $version = new GraphNode('laravel', '12', 'version', 'laravel:12', 'Laravel 12', [$source->id()]);
    $route = new GraphNode('laravel', '12', 'concept', 'route', 'Route', [$source->id()]);
    $basic = new GraphNode('laravel', '12', 'documentation_section', 'docs:routing#basic-routing', 'Basic Routing', [$source->id()]);
    $web = new GraphNode('laravel', '12', 'configuration', 'routes/web.php', 'routes/web.php', [$source->id()]);
    $facade = new GraphNode('laravel', '12', 'class', 'Illuminate\Support\Facades\Route', 'Route', [$source->id()], ['start_line' => 10, 'end_line' => 50]);

    $edges = [
        new GraphEdge('laravel', '12', 'contains', $version->id(), $route->id(), [$source->id()]),
        new GraphEdge('laravel', '12', 'contains', $version->id(), $web->id(), [$source->id()]),
        new GraphEdge('laravel', '12', 'documented_in', $route->id(), $basic->id(), [$source->id()]),
        new GraphEdge('laravel', '12', 'configured_by', $route->id(), $web->id(), [$source->id()]),
        new GraphEdge('laravel', '12', 'uses', $route->id(), $facade->id(), [$source->id()]),
        new GraphEdge('laravel', '12', 'uses', $web->id(), $facade->id(), [$source->id()]),
    ];

    $graph->replace('laravel', '12', [$source], [$version, $route, $basic, $web, $facade], $edges);
}

function seedProjectGraph(Graph $graph): void
{
    $source = new GraphSource('project', 'abc', 'workspace', '/tmp/project', 'Molly project graph');
    $workspace = new GraphNode('project', 'abc', 'workspace', '/tmp/project', 'Workspace', [$source->id()]);
    $task = new GraphNode('project', 'abc', 'task', 'task-1', 'greeting-task', [$source->id()]);
    $test = new GraphNode('project', 'abc', 'acceptance_test', 'task-1:tests/GreetingTest.php', 'tests/GreetingTest.php', [$source->id()]);
    $run = new GraphNode('project', 'abc', 'run', 'run-1', 'Attempt run-1', [$source->id()]);
    $evidence = new GraphNode('project', 'abc', 'verifier_evidence', 'run-1:pest', 'pest', [$source->id()]);

    $edges = [
        new GraphEdge('project', 'abc', 'runs_in', $task->id(), $workspace->id(), [$source->id()]),
        new GraphEdge('project', 'abc', 'verified_by', $task->id(), $test->id(), [$source->id()]),
        new GraphEdge('project', 'abc', 'produced', $task->id(), $run->id(), [$source->id()]),
        new GraphEdge('project', 'abc', 'produced', $run->id(), $evidence->id(), [$source->id()]),
    ];

    $graph->replace('project', 'abc', [$source], [$workspace, $task, $test, $run, $evidence], $edges);
}

it('builds a deterministic routing path from the laravel graph', function () {
    $graph = learningGraph();
    seedRoutingGraph($graph);
    $builder = new LearningPathBuilder($graph);

    $path = $builder->build([
        'id' => 'request-route-through-laravel',
        'title' => 'Request routing through Laravel',
        'description' => 'Follow a route through configuration to the facade.',
        'seed' => 'Route',
        'namespace' => 'laravel',
        'version' => '12',
        'chain' => [
            ['relation' => 'configured_by', 'direction' => 'outgoing', 'why' => 'A route is configured in routes/web.php.'],
            ['relation' => 'uses', 'direction' => 'outgoing', 'why' => 'The routes file uses the Route facade.'],
        ],
        'repository' => 'sifrious/molly',
        'revision_ref' => 'abc123',
    ]);

    expect($path)->toBeInstanceOf(LearningPathView::class)
        ->and($path->id)->toBe('request-route-through-laravel')
        ->and($path->freshness)->toBe(Freshness::Current)
        ->and($path->error)->toBeNull()
        ->and($path->gaps)->toBe([])
        ->and($path->complete)->toBeTrue()
        ->and($path->steps)->toHaveCount(3);

    expect($path->steps[0]->position)->toBe(1)
        ->and($path->steps[0]->node)->toBe('Route')
        ->and($path->steps[0]->nodeKind)->toBe('concept')
        ->and($path->steps[0]->nextRelationship)->toBe('configured_by');

    expect($path->steps[1]->position)->toBe(2)
        ->and($path->steps[1]->node)->toBe('routes/web.php')
        ->and($path->steps[1]->nodeKind)->toBe('configuration');

    expect($path->steps[2]->position)->toBe(3)
        ->and($path->steps[2]->node)->toBe('Route')
        ->and($path->steps[2]->nodeKind)->toBe('class');
});

it('produces identical paths from identical graphs', function () {
    $graph = learningGraph();
    seedRoutingGraph($graph);
    $builder = new LearningPathBuilder($graph);

    $template = [
        'id' => 'request-route-through-laravel',
        'title' => 'Request routing through Laravel',
        'description' => 'Test determinism.',
        'seed' => 'Route',
        'namespace' => 'laravel',
        'version' => '12',
        'chain' => [
            ['relation' => 'configured_by', 'direction' => 'outgoing', 'why' => 'Configuration.'],
            ['relation' => 'uses', 'direction' => 'outgoing', 'why' => 'Usage.'],
        ],
        'repository' => '',
        'revision_ref' => null,
    ];

    $first = $builder->build($template);
    $second = $builder->build($template);

    expect($first->toArray())->toBe($second->toArray());
});

it('reports a gap when connectivity is missing', function () {
    $graph = learningGraph();

    $source = new GraphSource('laravel', '12', 'documentation', 'routing', 'Laravel 12 Routing');
    $route = new GraphNode('laravel', '12', 'concept', 'route', 'Route', [$source->id()]);
    $graph->replace('laravel', '12', [$source], [$route], []);

    $builder = new LearningPathBuilder($graph);

    $path = $builder->build([
        'id' => 'missing-edge',
        'title' => 'Missing edge test',
        'description' => 'Test gap reporting.',
        'seed' => 'Route',
        'namespace' => 'laravel',
        'version' => '12',
        'chain' => [
            ['relation' => 'configured_by', 'direction' => 'outgoing', 'why' => 'Should fail.'],
        ],
        'repository' => '',
        'revision_ref' => null,
    ]);

    expect($path->freshness)->toBe(Freshness::PartiallyUpdated)
        ->and($path->gaps)->toHaveCount(1)
        ->and($path->gaps[0])->toContain('configured_by')
        ->and($path->complete)->toBeFalse()
        ->and($path->steps)->toHaveCount(1);
});

it('reports an error when the seed concept is missing', function () {
    $graph = learningGraph();

    $source = new GraphSource('laravel', '12', 'documentation', 'routing', 'Laravel 12 Routing');
    $node = new GraphNode('laravel', '12', 'concept', 'something', 'Something', [$source->id()]);
    $graph->replace('laravel', '12', [$source], [$node], []);

    $builder = new LearningPathBuilder($graph);

    $path = $builder->build([
        'id' => 'missing-seed',
        'title' => 'Missing seed test',
        'description' => 'Seed not found.',
        'seed' => 'NonexistentConcept',
        'namespace' => 'laravel',
        'version' => '12',
        'chain' => [],
        'repository' => '',
        'revision_ref' => null,
    ]);

    expect($path->freshness)->toBe(Freshness::Unavailable)
        ->and($path->error)->toContain('NonexistentConcept')
        ->and($path->steps)->toBe([]);
});

it('builds a project graph path from task to evidence', function () {
    $graph = learningGraph();
    seedProjectGraph($graph);
    $builder = new LearningPathBuilder($graph);

    $path = $builder->build([
        'id' => 'task-test-evidence',
        'title' => 'Task to test to evidence',
        'description' => 'Trace a project task through its acceptance test.',
        'seed' => 'Workspace',
        'namespace' => 'project',
        'version' => 'abc',
        'chain' => [
            ['relation' => 'runs_in', 'direction' => 'incoming', 'why' => 'Tasks run inside the workspace.'],
            ['relation' => 'verified_by', 'direction' => 'outgoing', 'why' => 'Each task links to its acceptance test.'],
        ],
        'repository' => '',
        'revision_ref' => null,
    ]);

    expect($path->steps)->toHaveCount(3)
        ->and($path->steps[0]->node)->toBe('Workspace')
        ->and($path->steps[1]->node)->toBe('greeting-task')
        ->and($path->steps[2]->node)->toBe('tests/GreetingTest.php')
        ->and($path->complete)->toBeTrue()
        ->and($path->gaps)->toBe([]);
});

it('round-trips LearningPathView through toArray/fromArray', function () {
    $graph = learningGraph();
    seedRoutingGraph($graph);
    $builder = new LearningPathBuilder($graph);

    $path = $builder->build([
        'id' => 'round-trip',
        'title' => 'Round trip test',
        'description' => 'Verify serialization.',
        'seed' => 'Route',
        'namespace' => 'laravel',
        'version' => '12',
        'chain' => [
            ['relation' => 'configured_by', 'direction' => 'outgoing', 'why' => 'Configuration.'],
        ],
        'repository' => 'sifrious/molly',
        'revision_ref' => 'abc',
    ]);

    $hydrated = LearningPathView::fromArray($path->toArray());

    expect($hydrated->toArray())->toBe($path->toArray());
});

it('round-trips LearningStepView through toArray/fromArray', function () {
    $step = new LearningStepView(
        position: 1,
        total: 3,
        node: 'Route',
        nodeKind: 'concept',
        why: 'Starting point.',
        citations: [new LearningCitation('/docs/routing.md', 'abc', 10, 50)],
        nextRelationship: 'configured_by',
        nextNode: 'routes/web.php',
        exercise: 'Read the routing docs.',
    );

    $hydrated = LearningStepView::fromArray($step->toArray());

    expect($hydrated->toArray())->toBe($step->toArray());
});

it('serializes citation objects in camelCase', function () {
    $citation = new LearningCitation('/src/Foo.php', 'main', 5, 20);
    $array = $citation->toArray();

    expect($array)->toHaveKeys(['path', 'revisionRef', 'lineStart', 'lineEnd'])
        ->and($array['path'])->toBe('/src/Foo.php')
        ->and($array['revisionRef'])->toBe('main')
        ->and($array['lineStart'])->toBe(5)
        ->and($array['lineEnd'])->toBe(20);
});

it('uses Merry-canonical freshness values', function () {
    expect(Freshness::Current->value)->toBe('current')
        ->and(Freshness::Stale->value)->toBe('stale')
        ->and(Freshness::PartiallyUpdated->value)->toBe('partially_updated')
        ->and(Freshness::Unavailable->value)->toBe('unavailable')
        ->and(Freshness::Unknown->value)->toBe('unknown');
});

it('uses Merry-canonical path keys in toArray output', function () {
    $graph = learningGraph();
    seedRoutingGraph($graph);
    $builder = new LearningPathBuilder($graph);

    $path = $builder->build([
        'id' => 'key-test',
        'title' => 'Key test',
        'description' => 'Verify Merry keys.',
        'seed' => 'Route',
        'namespace' => 'laravel',
        'version' => '12',
        'chain' => [],
        'repository' => 'sifrious/molly',
        'revision_ref' => 'abc',
    ]);

    $array = $path->toArray();
    expect($array)->toHaveKeys([
        'id', 'title', 'description', 'repository', 'revision_ref',
        'freshness', 'steps', 'error',
        'template_id', 'prerequisites', 'gaps', 'complete',
    ]);
});

it('uses Merry-canonical step keys in toArray output', function () {
    $graph = learningGraph();
    seedRoutingGraph($graph);
    $builder = new LearningPathBuilder($graph);

    $path = $builder->build([
        'id' => 'step-key-test',
        'title' => 'Step key test',
        'description' => 'Verify Merry step keys.',
        'seed' => 'Route',
        'namespace' => 'laravel',
        'version' => '12',
        'chain' => [
            ['relation' => 'configured_by', 'direction' => 'outgoing', 'why' => 'Config.'],
        ],
        'repository' => '',
        'revision_ref' => null,
    ]);

    $step = $path->steps[0]->toArray();
    expect($step)->toHaveKeys([
        'position', 'total', 'node', 'node_kind', 'why',
        'citations', 'next_relationship', 'next_node', 'exercise',
    ]);
});
