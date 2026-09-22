<?php

use Sifrious\Molly\Knowledge\LaravelTestingGraph;

it('builds the testing graph through LaravelGraphBuilder with Pest and PHPUnit', function () {
    $graph = app(LaravelTestingGraph::class)->build('12');

    $keys = collect($graph['nodes'])->pluck('key')->all();
    expect($keys)->toContain('pest')
        ->and($keys)->toContain('phpunit')
        ->and($keys)->toContain('tests/Feature')
        ->and($keys)->toContain('docs:testing#introduction')
        ->and($keys)->toContain('Illuminate\Foundation\Testing\TestCase');
});
