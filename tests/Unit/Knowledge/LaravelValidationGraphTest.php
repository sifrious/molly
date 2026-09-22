<?php

use Illuminate\Validation\Validator;
use Sifrious\Molly\Knowledge\LaravelValidationGraph;

it('proves validation graph compatibility after LaravelGraphBuilder migration', function () {
    $first = app(LaravelValidationGraph::class)->build('12');
    $second = app(LaravelValidationGraph::class)->build('12');

    expect($first['sources'])->toEqual($second['sources'])
        ->and($first['nodes'])->toEqual($second['nodes'])
        ->and($first['edges'])->toEqual($second['edges']);

    $nodeByKey = collect($first['nodes'])->keyBy('key');
    expect($nodeByKey->keys()->all())->toContain('validation')
        ->and($nodeByKey->keys()->all())->toContain('docs:validation#introduction')
        ->and($nodeByKey->keys()->all())->toContain(Validator::class);

    expect($nodeByKey['validation']->label)->toBe('Validation');

    $version = collect($first['nodes'])->firstWhere('type', 'version');
    $edgeTypes = collect($first['edges'])->map(fn ($edge) => [
        'relation' => $edge->relation,
        'from' => $edge->from,
        'to' => $edge->to,
    ]);

    expect($edgeTypes)->toContain([
        'relation' => 'contains',
        'from' => $version->id(),
        'to' => $nodeByKey['validation']->id(),
    ])->toContain([
        'relation' => 'documented_in',
        'from' => $nodeByKey['validation']->id(),
        'to' => $nodeByKey['docs:validation#introduction']->id(),
    ])->toContain([
        'relation' => 'uses',
        'from' => $nodeByKey['validation']->id(),
        'to' => $nodeByKey[Validator::class]->id(),
    ]);

    expect(collect($first['sources'])->map->id()->unique()->count())->toBe(count($first['sources']));
    expect(collect($first['nodes'])->map->id()->unique()->count())->toBe(count($first['nodes']));
    expect(collect($first['edges'])->map->id()->unique()->count())->toBe(count($first['edges']));

    $docSource = collect($first['sources'])->firstWhere('key', 'validation');
    $reflected = collect($first['sources'])->firstWhere('key', Validator::class);
    expect($docSource->digest)->not->toBeEmpty()
        ->and($reflected)->not->toBeNull()
        ->and($reflected->type)->toBe('framework_source')
        ->and($reflected->digest)->not->toBeEmpty()
        ->and($reflected->location)->not->toBeEmpty();
});
