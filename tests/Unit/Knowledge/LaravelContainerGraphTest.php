<?php

use Illuminate\Container\Container;
use Sifrious\Molly\Knowledge\LaravelContainerGraph;

it('builds the container graph through LaravelGraphBuilder', function () {
    $graph = app(LaravelContainerGraph::class)->build('12');

    $keys = collect($graph['nodes'])->pluck('key')->all();
    expect($keys)->toContain('container')
        ->and($keys)->toContain('docs:container#zero-configuration-resolution')
        ->and($keys)->toContain(Container::class);
});
