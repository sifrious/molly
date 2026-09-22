<?php

use Illuminate\Support\Facades\Route;
use Sifrious\Molly\Knowledge\LaravelRoutingGraph;

it('proves routing graph compatibility after LaravelGraphBuilder migration', function () {
    $first = app(LaravelRoutingGraph::class)->build('12');
    $second = app(LaravelRoutingGraph::class)->build('12');

    expect($first['sources'])->toEqual($second['sources'])
        ->and($first['nodes'])->toEqual($second['nodes'])
        ->and($first['edges'])->toEqual($second['edges']);

    $nodeByKey = collect($first['nodes'])->keyBy('key');
    expect($nodeByKey->keys()->all())->toContain('route')
        ->and($nodeByKey->keys()->all())->toContain('routes/web.php')
        ->and($nodeByKey->keys()->all())->toContain('docs:routing#basic-routing')
        ->and($nodeByKey->keys()->all())->toContain(Route::class);

    expect($nodeByKey['route']->label)->toBe('Route')
        ->and($nodeByKey['routes/web.php']->label)->toBe('routes/web.php')
        ->and($nodeByKey['docs:routing#basic-routing']->label)->toBe('Basic Routing');

    $version = collect($first['nodes'])->firstWhere('type', 'version');
    expect($version)->not->toBeNull();

    $edgeTypes = collect($first['edges'])->map(fn ($edge) => [
        'relation' => $edge->relation,
        'from' => $edge->from,
        'to' => $edge->to,
    ]);

    expect($edgeTypes)->toContain([
        'relation' => 'contains',
        'from' => $version->id(),
        'to' => $nodeByKey['route']->id(),
    ])->toContain([
        'relation' => 'contains',
        'from' => $version->id(),
        'to' => $nodeByKey['routes/web.php']->id(),
    ])->toContain([
        'relation' => 'documented_in',
        'from' => $nodeByKey['route']->id(),
        'to' => $nodeByKey['docs:routing#basic-routing']->id(),
    ])->toContain([
        'relation' => 'configured_by',
        'from' => $nodeByKey['route']->id(),
        'to' => $nodeByKey['routes/web.php']->id(),
    ])->toContain([
        'relation' => 'uses',
        'from' => $nodeByKey['route']->id(),
        'to' => $nodeByKey[Route::class]->id(),
    ])->toContain([
        'relation' => 'uses',
        'from' => $nodeByKey['routes/web.php']->id(),
        'to' => $nodeByKey[Route::class]->id(),
    ]);

    expect(collect($first['sources'])->map->id()->unique()->count())
        ->toBe(count($first['sources']));
    expect(collect($first['nodes'])->map->id()->unique()->count())
        ->toBe(count($first['nodes']));
    expect(collect($first['edges'])->map->id()->unique()->count())
        ->toBe(count($first['edges']));
});
