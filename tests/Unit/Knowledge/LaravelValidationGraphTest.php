<?php

use Illuminate\Validation\Validator;
use Sifrious\Molly\Knowledge\LaravelValidationGraph;

it('builds the validation graph through LaravelGraphBuilder', function () {
    $graph = app(LaravelValidationGraph::class)->build('12');

    $keys = collect($graph['nodes'])->pluck('key')->all();
    expect($keys)->toContain('validation')
        ->and($keys)->toContain('docs:validation#introduction')
        ->and($keys)->toContain(Validator::class);
});
