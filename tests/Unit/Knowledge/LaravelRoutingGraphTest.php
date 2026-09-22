<?php

use Illuminate\Support\Facades\Route;
use Sifrious\Molly\Knowledge\LaravelRoutingGraph;

it('builds the routing graph through LaravelGraphBuilder with Route facade', function () {
    $graph = app(LaravelRoutingGraph::class)->build('12');

    $keys = collect($graph['nodes'])->pluck('key')->all();
    expect($keys)->toContain('route')
        ->and($keys)->toContain('routes/web.php')
        ->and($keys)->toContain('docs:routing#basic-routing')
        ->and($keys)->toContain(Route::class);
});
