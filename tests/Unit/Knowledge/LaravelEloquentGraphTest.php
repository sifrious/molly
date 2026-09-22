<?php

use Illuminate\Database\Eloquent\Model;
use Sifrious\Molly\Knowledge\LaravelEloquentGraph;

it('proves eloquent graph compatibility after LaravelGraphBuilder migration', function () {
    $first = app(LaravelEloquentGraph::class)->build('12');
    $second = app(LaravelEloquentGraph::class)->build('12');

    expect($first['sources'])->toEqual($second['sources'])
        ->and($first['nodes'])->toEqual($second['nodes'])
        ->and($first['edges'])->toEqual($second['edges']);

    $nodeByKey = collect($first['nodes'])->keyBy('key');
    expect($nodeByKey->keys()->all())->toContain('eloquent')
        ->and($nodeByKey->keys()->all())->toContain('docs:eloquent#introduction')
        ->and($nodeByKey->keys()->all())->toContain(Model::class);

    expect($nodeByKey['eloquent']->label)->toBe('Eloquent');

    $version = collect($first['nodes'])->firstWhere('type', 'version');
    $edgeTypes = collect($first['edges'])->map(fn ($edge) => [
        'relation' => $edge->relation,
        'from' => $edge->from,
        'to' => $edge->to,
    ]);

    expect($edgeTypes)->toContain([
        'relation' => 'contains',
        'from' => $version->id(),
        'to' => $nodeByKey['eloquent']->id(),
    ])->toContain([
        'relation' => 'documented_in',
        'from' => $nodeByKey['eloquent']->id(),
        'to' => $nodeByKey['docs:eloquent#introduction']->id(),
    ])->toContain([
        'relation' => 'uses',
        'from' => $nodeByKey['eloquent']->id(),
        'to' => $nodeByKey[Model::class]->id(),
    ]);

    expect(collect($first['sources'])->map->id()->unique()->count())->toBe(count($first['sources']));
    expect(collect($first['nodes'])->map->id()->unique()->count())->toBe(count($first['nodes']));
    expect(collect($first['edges'])->map->id()->unique()->count())->toBe(count($first['edges']));
});
