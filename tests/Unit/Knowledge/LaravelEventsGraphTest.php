<?php

use Illuminate\Events\Dispatcher;
use Sifrious\Molly\Knowledge\LaravelEventsGraph;

it('proves events graph compatibility after LaravelGraphBuilder migration', function () {
    $first = app(LaravelEventsGraph::class)->build('12');
    $second = app(LaravelEventsGraph::class)->build('12');

    expect($first['sources'])->toEqual($second['sources'])
        ->and($first['nodes'])->toEqual($second['nodes'])
        ->and($first['edges'])->toEqual($second['edges']);

    $nodeByKey = collect($first['nodes'])->keyBy('key');
    expect($nodeByKey->keys()->all())->toContain('events')
        ->and($nodeByKey->keys()->all())->toContain('docs:events#introduction')
        ->and($nodeByKey->keys()->all())->toContain(Dispatcher::class);

    expect($nodeByKey['events']->label)->toBe('Events');

    $version = collect($first['nodes'])->firstWhere('type', 'version');
    $edgeTypes = collect($first['edges'])->map(fn ($edge) => [
        'relation' => $edge->relation,
        'from' => $edge->from,
        'to' => $edge->to,
    ]);

    expect($edgeTypes)->toContain([
        'relation' => 'contains',
        'from' => $version->id(),
        'to' => $nodeByKey['events']->id(),
    ])->toContain([
        'relation' => 'documented_in',
        'from' => $nodeByKey['events']->id(),
        'to' => $nodeByKey['docs:events#introduction']->id(),
    ])->toContain([
        'relation' => 'uses',
        'from' => $nodeByKey['events']->id(),
        'to' => $nodeByKey[Dispatcher::class]->id(),
    ]);

    expect(collect($first['sources'])->map->id()->unique()->count())->toBe(count($first['sources']));
    expect(collect($first['nodes'])->map->id()->unique()->count())->toBe(count($first['nodes']));
    expect(collect($first['edges'])->map->id()->unique()->count())->toBe(count($first['edges']));

    $docSource = collect($first['sources'])->firstWhere('key', 'events');
    $reflected = collect($first['sources'])->firstWhere('key', Dispatcher::class);
    expect($docSource->digest)->not->toBeEmpty()
        ->and($reflected)->not->toBeNull()
        ->and($reflected->type)->toBe('framework_source')
        ->and($reflected->digest)->not->toBeEmpty()
        ->and($reflected->location)->not->toBeEmpty();
});
