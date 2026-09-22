<?php

use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphEdge;
use Sifrious\Molly\Knowledge\GraphNode;
use Sifrious\Molly\Knowledge\GraphQuery;
use Sifrious\Molly\Knowledge\GraphSchema;
use Sifrious\Molly\Knowledge\GraphSource;

it('batch-hydrates provenance for nodes and edges while preserving order', function () {
    $path = sys_get_temp_dir().'/molly-graph-batch-'.uniqid('', true).'.sqlite';
    $graph = new Graph(new GraphSchema, $path);

    $doc = new GraphSource('laravel', '12', 'documentation', 'queues', 'Queues', 'https://example.test/queues', 'rev-a', 'digest-a', ['kind' => 'docs']);
    $src = new GraphSource('laravel', '12', 'framework_source', 'Illuminate\\Queue\\Queue', 'Queue', 'vendor/laravel/framework/src/Queue.php', '12.0.0', 'digest-b', ['kind' => 'source']);
    $queue = new GraphNode('laravel', '12', 'concept', 'queue', 'Queue', [$doc->id(), $src->id()], ['rank' => 1]);
    $job = new GraphNode('laravel', '12', 'concept', 'job', 'Job', [$doc->id()], ['rank' => 2]);
    $uses = new GraphEdge('laravel', '12', 'uses', $queue->id(), $job->id(), [$src->id(), $doc->id()], ['weight' => 1]);

    $graph->replace('laravel', '12', [$doc, $src], [$queue, $job], [$uses]);

    $result = $graph->query(new GraphQuery('laravel', '12', 'Queue', depth: 1, limit: 20))->toArray();

    expect($result['truncated'])->toBeFalse()
        ->and($result['nodes'])->not->toBeEmpty()
        ->and($result['edges'])->not->toBeEmpty();

    $queueNode = collect($result['nodes'])->firstWhere('key', 'queue');
    expect($queueNode)->not->toBeNull()
        ->and(collect($queueNode['sources'])->pluck('key')->all())->toBe(['queues', 'Illuminate\\Queue\\Queue'])
        ->and($queueNode['sources'][0]['metadata'])->toBe(['kind' => 'docs'])
        ->and($queueNode['sources'][1]['metadata'])->toBe(['kind' => 'source']);

    $edge = collect($result['edges'])->firstWhere('relation', 'uses');
    expect($edge)->not->toBeNull()
        ->and(collect($edge['sources'])->pluck('key')->all())->toBe(['queues', 'Illuminate\\Queue\\Queue']);

    expect(collect($result['nodes'])->pluck('key')->all())->toContain('queue', 'job');

    @unlink($path);
});

it('preserves query limits and truncation after batch provenance hydration', function () {
    $path = sys_get_temp_dir().'/molly-graph-batch-limit-'.uniqid('', true).'.sqlite';
    $graph = new Graph(new GraphSchema, $path);

    $doc = new GraphSource('laravel', '12', 'documentation', 'queues', 'Queues', null, null, null);
    $nodes = [];
    $edges = [];
    $seed = new GraphNode('laravel', '12', 'concept', 'queue', 'Queue', [$doc->id()]);
    $nodes[] = $seed;
    for ($i = 0; $i < 5; $i++) {
        $node = new GraphNode('laravel', '12', 'concept', 'related-'.$i, 'Related '.$i, [$doc->id()]);
        $nodes[] = $node;
        $edges[] = new GraphEdge('laravel', '12', 'related_to', $seed->id(), $node->id(), [$doc->id()]);
    }

    $graph->replace('laravel', '12', [$doc], $nodes, $edges);

    $limited = $graph->query(new GraphQuery('laravel', '12', 'Queue', depth: 2, limit: 2))->toArray();
    expect($limited['truncated'])->toBeTrue()
        ->and($limited['nodes'])->toHaveCount(2);

    foreach ($limited['nodes'] as $node) {
        expect($node['sources'])->not->toBeEmpty();
    }

    @unlink($path);
});
