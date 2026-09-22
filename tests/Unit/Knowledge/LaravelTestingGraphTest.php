<?php

use Illuminate\Foundation\Testing\TestCase;
use Sifrious\Molly\Knowledge\LaravelTestingGraph;

it('proves testing graph compatibility after LaravelGraphBuilder migration', function () {
    $first = app(LaravelTestingGraph::class)->build('12');
    $second = app(LaravelTestingGraph::class)->build('12');

    expect($first['sources'])->toEqual($second['sources'])
        ->and($first['nodes'])->toEqual($second['nodes'])
        ->and($first['edges'])->toEqual($second['edges']);

    $nodeByKey = collect($first['nodes'])->keyBy('key');
    expect($nodeByKey->keys()->all())->toContain('pest')
        ->and($nodeByKey->keys()->all())->toContain('phpunit')
        ->and($nodeByKey->keys()->all())->toContain('tests/Feature')
        ->and($nodeByKey->keys()->all())->toContain('docs:testing#introduction')
        ->and($nodeByKey->keys()->all())->toContain(TestCase::class);

    expect($nodeByKey['pest']->label)->toBe('Pest')
        ->and($nodeByKey['phpunit']->label)->toBe('PHPUnit')
        ->and($nodeByKey['tests/Feature']->label)->toBe('tests/Feature');

    $version = collect($first['nodes'])->firstWhere('type', 'version');
    $edgeTypes = collect($first['edges'])->map(fn ($edge) => [
        'relation' => $edge->relation,
        'from' => $edge->from,
        'to' => $edge->to,
    ]);

    expect($edgeTypes)->toContain([
        'relation' => 'contains',
        'from' => $version->id(),
        'to' => $nodeByKey['pest']->id(),
    ])->toContain([
        'relation' => 'contains',
        'from' => $version->id(),
        'to' => $nodeByKey['phpunit']->id(),
    ])->toContain([
        'relation' => 'contains',
        'from' => $version->id(),
        'to' => $nodeByKey['tests/Feature']->id(),
    ])->toContain([
        'relation' => 'documented_in',
        'from' => $nodeByKey['pest']->id(),
        'to' => $nodeByKey['docs:testing#introduction']->id(),
    ])->toContain([
        'relation' => 'documented_in',
        'from' => $nodeByKey['phpunit']->id(),
        'to' => $nodeByKey['docs:testing#introduction']->id(),
    ])->toContain([
        'relation' => 'documented_in',
        'from' => $nodeByKey['tests/Feature']->id(),
        'to' => $nodeByKey['docs:testing#introduction']->id(),
    ])->toContain([
        'relation' => 'related_to',
        'from' => $nodeByKey['pest']->id(),
        'to' => $nodeByKey['phpunit']->id(),
    ])->toContain([
        'relation' => 'tested_by',
        'from' => $nodeByKey['tests/Feature']->id(),
        'to' => $nodeByKey['pest']->id(),
    ])->toContain([
        'relation' => 'uses',
        'from' => $nodeByKey['phpunit']->id(),
        'to' => $nodeByKey[TestCase::class]->id(),
    ])->toContain([
        'relation' => 'uses',
        'from' => $nodeByKey['pest']->id(),
        'to' => $nodeByKey[TestCase::class]->id(),
    ]);

    expect(collect($first['sources'])->map->id()->unique()->count())->toBe(count($first['sources']));
    expect(collect($first['nodes'])->map->id()->unique()->count())->toBe(count($first['nodes']));
    expect(collect($first['edges'])->map->id()->unique()->count())->toBe(count($first['edges']));
    expect(collect($first['sources'])->firstWhere('key', 'testing')->digest)->not->toBeEmpty();
});
