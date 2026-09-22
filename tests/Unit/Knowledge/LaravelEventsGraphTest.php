<?php

use Illuminate\Events\Dispatcher;
use Sifrious\Molly\Knowledge\LaravelEventsGraph;

it('builds the events graph through LaravelGraphBuilder with Dispatcher source', function () {
    $graph = app(LaravelEventsGraph::class)->build('12');

    $keys = collect($graph['nodes'])->pluck('key')->all();
    expect($keys)->toContain('events')
        ->and($keys)->toContain('docs:events#introduction')
        ->and($keys)->toContain(Dispatcher::class);

    $events = collect($graph['nodes'])->firstWhere('key', 'events');
    $introduction = collect($graph['nodes'])->firstWhere('key', 'docs:events#introduction');
    $dispatcher = collect($graph['nodes'])->firstWhere('key', Dispatcher::class);
    $version = collect($graph['nodes'])->firstWhere('type', 'version');

    expect(collect($graph['edges'])->map(fn ($edge) => [
        'relation' => $edge->relation,
        'from' => $edge->from,
        'to' => $edge->to,
    ]))->toContain([
        'relation' => 'contains',
        'from' => $version->id(),
        'to' => $events->id(),
    ])->toContain([
        'relation' => 'documented_in',
        'from' => $events->id(),
        'to' => $introduction->id(),
    ])->toContain([
        'relation' => 'uses',
        'from' => $events->id(),
        'to' => $dispatcher->id(),
    ]);
});
