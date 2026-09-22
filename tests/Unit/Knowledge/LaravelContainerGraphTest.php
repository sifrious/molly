<?php

use Illuminate\Container\Container;
use Sifrious\Molly\Knowledge\LaravelContainerGraph;

it('proves container graph compatibility after LaravelGraphBuilder migration', function () {
    $first = app(LaravelContainerGraph::class)->build('12');
    $second = app(LaravelContainerGraph::class)->build('12');

    expect($first['sources'])->toEqual($second['sources'])
        ->and($first['nodes'])->toEqual($second['nodes'])
        ->and($first['edges'])->toEqual($second['edges']);

    $nodeByKey = collect($first['nodes'])->keyBy('key');
    expect($nodeByKey->keys()->all())->toContain('container')
        ->and($nodeByKey->keys()->all())->toContain('docs:container#zero-configuration-resolution')
        ->and($nodeByKey->keys()->all())->toContain(Container::class);

    expect($nodeByKey['container']->label)->toBe('Container');

    $version = collect($first['nodes'])->firstWhere('type', 'version');
    $edgeTypes = collect($first['edges'])->map(fn ($edge) => [
        'relation' => $edge->relation,
        'from' => $edge->from,
        'to' => $edge->to,
    ]);

    expect($edgeTypes)->toContain([
        'relation' => 'contains',
        'from' => $version->id(),
        'to' => $nodeByKey['container']->id(),
    ])->toContain([
        'relation' => 'documented_in',
        'from' => $nodeByKey['container']->id(),
        'to' => $nodeByKey['docs:container#zero-configuration-resolution']->id(),
    ])->toContain([
        'relation' => 'uses',
        'from' => $nodeByKey['container']->id(),
        'to' => $nodeByKey[Container::class]->id(),
    ]);

    expect(collect($first['sources'])->map->id()->unique()->count())->toBe(count($first['sources']));
    expect(collect($first['nodes'])->map->id()->unique()->count())->toBe(count($first['nodes']));
    expect(collect($first['edges'])->map->id()->unique()->count())->toBe(count($first['edges']));

    $docSource = collect($first['sources'])->firstWhere('key', 'container');
    $reflected = collect($first['sources'])->firstWhere('key', Container::class);
    expect($docSource->digest)->not->toBeEmpty()
        ->and($reflected)->not->toBeNull()
        ->and($reflected->type)->toBe('framework_source')
        ->and($reflected->digest)->not->toBeEmpty()
        ->and($reflected->location)->not->toBeEmpty();
});
