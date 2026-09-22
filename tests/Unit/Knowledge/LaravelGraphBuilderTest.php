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

it('preserves graph identities across sections symbols digests and ordering', function () {
    $builder = new LaravelGraphBuilder('laravel', '12');
    $sections = $builder->parseSections("## Zero Configuration Resolution\n\n### Nested Detail\n");
    expect($sections[0]['slug'])->toContain('zero')
        ->and($sections[0]['level'])->toBe(2)
        ->and($sections[1]['level'])->toBe(3);

    $doc = $builder->addSource(new GraphSource(
        'laravel', '12', 'documentation', 'container', 'Laravel 12 Container',
        'docs/container.md', '12', 'abc',
        ['retrieved_at' => $builder->retrievedAt('Retrieved 2026-09-21.')],
    ));
    expect($doc->metadata['retrieved_at'])->toBe('2026-09-21');

    $symbol = $builder->addReflectedSymbol(GraphSource::class);
    expect($symbol['node']->type)->toBe('class')
        ->and($symbol['node']->metadata['start_line'])->toBeInt()
        ->and($symbol['node']->metadata['end_line'])->toBeInt()
        ->and($symbol['source']->digest)->toBeString()
        ->and($symbol['node']->sourceIds)->toBe([$symbol['source']->id()]);

    $method = $builder->addReflectedMethod(GraphSource::class, 'id');
    expect($method['node']->type)->toBe('method')
        ->and($method['node']->metadata['start_line'])->toBeLessThanOrEqual($method['node']->metadata['end_line']);

    $builder->addNode('concept', 'z-last', 'Z', [$doc->id()]);
    $builder->addNode('concept', 'a-first', 'A', [$doc->id()]);
    $final = $builder->finalize();
    $ids = array_map(fn ($node) => $node->id(), $final['nodes']);
    $sorted = $ids;
    sort($sorted);
    expect($ids)->toBe($sorted);

    expect(fn () => $builder->addReflectedSymbol('Missing\\Symbol\\Here'))
        ->toThrow(RuntimeException::class, 'KNOWLEDGE_SYMBOL_MISSING:');

    $foreign = new LaravelGraphBuilder('other', '12');
    expect(fn () => $foreign->addSource(new GraphSource('laravel', '12', 'documentation', 'x', 'X')))
        ->toThrow(RuntimeException::class, 'KNOWLEDGE_SCOPE_INVALID:');
});
