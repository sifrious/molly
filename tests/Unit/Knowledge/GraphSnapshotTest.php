<?php

use Sifrious\Molly\Knowledge\GraphEdge;
use Sifrious\Molly\Knowledge\GraphNode;
use Sifrious\Molly\Knowledge\GraphSnapshot;
use Sifrious\Molly\Knowledge\GraphSource;

function snapSource(string $key = 'doc'): GraphSource
{
    return new GraphSource('laravel', '12', 'document', $key, 'Doc '.$key);
}

it('accepts a scoped snapshot with provenance and endpoints', function () {
    $source = snapSource();
    $from = new GraphNode('laravel', '12', 'concept', 'a', 'A', [$source->id()]);
    $to = new GraphNode('laravel', '12', 'concept', 'b', 'B', [$source->id()]);
    $edge = new GraphEdge('laravel', '12', 'related', $from->id(), $to->id(), [$source->id()]);

    $snapshot = new GraphSnapshot('laravel', '12', [$source], [$from, $to], [$edge]);

    expect($snapshot->sources)->toHaveCount(1)
        ->and($snapshot->nodes)->toHaveCount(2)
        ->and($snapshot->edges)->toHaveCount(1);
});

it('rejects scope mismatches', function () {
    $source = snapSource();
    $node = new GraphNode('other', '12', 'concept', 'a', 'A', [$source->id()]);

    expect(fn () => new GraphSnapshot('laravel', '12', [$source], [$node], []))
        ->toThrow(RuntimeException::class, 'KNOWLEDGE_SCOPE_INVALID:');
});

it('rejects missing provenance', function () {
    $source = snapSource();
    $node = new GraphNode('laravel', '12', 'concept', 'a', 'A', []);

    expect(fn () => new GraphSnapshot('laravel', '12', [$source], [$node], []))
        ->toThrow(RuntimeException::class, 'KNOWLEDGE_PROVENANCE_REQUIRED:');
});

it('rejects edges without endpoints', function () {
    $source = snapSource();
    $from = new GraphNode('laravel', '12', 'concept', 'a', 'A', [$source->id()]);
    $edge = new GraphEdge('laravel', '12', 'related', $from->id(), hash('sha256', 'missing'), [$source->id()]);

    expect(fn () => new GraphSnapshot('laravel', '12', [$source], [$from], [$edge]))
        ->toThrow(RuntimeException::class, 'KNOWLEDGE_EDGE_INVALID:');
});

it('round-trips toArray/fromArray without shape drift', function () {
    $source = snapSource();
    $from = new GraphNode('laravel', '12', 'concept', 'a', 'A', [$source->id()], ['k' => 1]);
    $to = new GraphNode('laravel', '12', 'concept', 'b', 'B', [$source->id()]);
    $edge = new GraphEdge('laravel', '12', 'related', $from->id(), $to->id(), [$source->id()]);
    $original = new GraphSnapshot('laravel', '12', [$source], [$from, $to], [$edge]);

    $hydrated = GraphSnapshot::fromArray($original->toArray());

    expect($hydrated->toArray())->toBe($original->toArray());
});

it('rejects incomplete cached snapshots', function () {
    expect(fn () => GraphSnapshot::fromArray(['namespace' => 'laravel']))
        ->toThrow(RuntimeException::class, 'KNOWLEDGE_SNAPSHOT_INVALID:');
});
