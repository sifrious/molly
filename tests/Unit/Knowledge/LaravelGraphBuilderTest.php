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

it('parses level 2-4 headings and retrieved-at metadata', function () {
    $builder = new LaravelGraphBuilder('laravel', '12');
    $content = "# Title\n\n## Intro\n\n### Detail\n\n#### Nested\n\nRetrieved 2026-09-21.\n";

    $sections = $builder->parseSections($content);
    expect($sections)->toHaveCount(3)
        ->and($sections[0]['level'])->toBe(2)
        ->and($builder->retrievedAt($content))->toBe('2026-09-21');

    expect(fn () => $builder->assertGuideTitleHasLaravelMajor('Container Guide', 'container'))
        ->toThrow(RuntimeException::class, 'KNOWLEDGE_DOC_INVALID:');
    $builder->assertGuideTitleHasLaravelMajor('Laravel 12 Container', 'container');
});

it('reflects an installed class with line ranges and digests', function () {
    $builder = new LaravelGraphBuilder('laravel', '12');
    $reflected = $builder->addReflectedSymbol(GraphSource::class);
    $final = $builder->finalize();

    expect($reflected['node']->metadata['start_line'])->toBeInt()
        ->and($reflected['source']->digest)->not->toBeNull()
        ->and($final['nodes'])->not->toBeEmpty();
});

it('fails when a symbol is missing', function () {
    $builder = new LaravelGraphBuilder('laravel', '12');
    expect(fn () => $builder->addReflectedSymbol('Definitely\\Missing\\Symbol'))
        ->toThrow(RuntimeException::class, 'KNOWLEDGE_SYMBOL_MISSING:');
});
