<?php

use Illuminate\Database\Eloquent\Model;
use Sifrious\Molly\Knowledge\LaravelEloquentGraph;

it('builds the eloquent graph through LaravelGraphBuilder', function () {
    $graph = app(LaravelEloquentGraph::class)->build('12');

    $keys = collect($graph['nodes'])->pluck('key')->all();
    expect($keys)->toContain('eloquent')
        ->and($keys)->toContain('docs:eloquent#introduction')
        ->and($keys)->toContain(Model::class);
});
