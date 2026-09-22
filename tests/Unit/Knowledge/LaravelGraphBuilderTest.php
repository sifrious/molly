<?php

use Sifrious\Molly\Knowledge\GraphSource;
use Sifrious\Molly\Knowledge\LaravelGraphBuilder;

it('accumulates sources nodes and edges deterministically with stable-id dedupe', function () {
    $builder = new LaravelGraphBuilder('laravel', '12');
    $source = new GraphSource('laravel', '12', 'document', 'guide', 'Guide');
    $builder->addSource($source);
    $builder->addSource($source); // dedupe by id
    $a = $builder->addNode('concept', 'a', 'A', [$source->id()]);
    $b = $builder->addNode('concept', 'b', 'B', [$source->id()]);
    $builder->addEdge('related', $a, $b, [$source->id()]);
    $builder->addEdge('related', $a, $b, [$source->id()]); // dedupe

    $final = $builder->finalize();

    expect($final['sources'])->toHaveCount(1)
        ->and($final['nodes'])->toHaveCount(2)
        ->and($final['edges'])->toHaveCount(1)
        ->and(array_keys($final))->toBe(['sources', 'nodes', 'edges']);
});

it('rejects provenance outside the builder', function () {
    $builder = new LaravelGraphBuilder('laravel', '12');

    expect(fn () => $builder->addNode('concept', 'a', 'A', ['missing']))
        ->toThrow(RuntimeException::class, 'KNOWLEDGE_SOURCE_INVALID:');
});
