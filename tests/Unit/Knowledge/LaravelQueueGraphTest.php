<?php

use Sifrious\Molly\Knowledge\LaravelQueueGraph;

it('builds the queue graph through LaravelGraphBuilder with required sections and concepts', function () {
    $graph = app(LaravelQueueGraph::class)->build('12');

    expect($graph['sources'])->not->toBeEmpty()
        ->and($graph['nodes'])->not->toBeEmpty()
        ->and($graph['edges'])->not->toBeEmpty();

    $keys = collect($graph['nodes'])->pluck('key')->all();
    expect($keys)->toContain('queue')
        ->and($keys)->toContain('job')
        ->and($keys)->toContain('docs:queues#introduction')
        ->and($keys)->toContain('docs:queues#connections-vs-queues')
        ->and($keys)->toContain('Illuminate\Contracts\Queue\ShouldQueue');
});
