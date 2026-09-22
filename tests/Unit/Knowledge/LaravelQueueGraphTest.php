<?php

use Sifrious\Molly\Knowledge\LaravelQueueGraph;

it('proves queue graph compatibility after LaravelGraphBuilder migration', function () {
    $first = app(LaravelQueueGraph::class)->build('12');
    $second = app(LaravelQueueGraph::class)->build('12');

    expect($first['sources'])->toEqual($second['sources'])
        ->and($first['nodes'])->toEqual($second['nodes'])
        ->and($first['edges'])->toEqual($second['edges']);

    $keys = collect($first['nodes'])->pluck('key')->all();
    expect($keys)->toContain('queue')
        ->and($keys)->toContain('job')
        ->and($keys)->toContain('retry')
        ->and($keys)->toContain('worker')
        ->and($keys)->toContain('config/queue.php')
        ->and($keys)->toContain('docs:queues#introduction')
        ->and($keys)->toContain('docs:queues#connections-vs-queues')
        ->and($keys)->toContain('Illuminate\Contracts\Queue\ShouldQueue')
        ->and($keys)->toContain('Illuminate\Queue\InteractsWithQueue::attempts')
        ->and($keys)->toContain('Illuminate\Support\Testing\Fakes\QueueFake::assertPushed');

    expect(collect($first['sources'])->map->id()->unique()->count())->toBe(count($first['sources']));
    expect(collect($first['nodes'])->map->id()->unique()->count())->toBe(count($first['nodes']));
    expect(collect($first['edges'])->map->id()->unique()->count())->toBe(count($first['edges']));

    $relations = collect($first['edges'])->pluck('relation')->unique()->sort()->values()->all();
    expect($relations)->toContain('contains')
        ->and($relations)->toContain('documented_in')
        ->and($relations)->toContain('configured_by')
        ->and($relations)->toContain('related_to')
        ->and($relations)->toContain('uses')
        ->and($relations)->toContain('implements')
        ->and($relations)->toContain('tested_by');
});
