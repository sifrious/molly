<?php

use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphSchema;

it('injects GraphSchema into Graph and opens schema-compatible sqlite', function () {
    $path = sys_get_temp_dir().'/molly-graph-inject-'.uniqid('', true).'.sqlite';
    $schema = new GraphSchema;
    $graph = new Graph($schema, $path);

    $r = new ReflectionClass($graph);
    $prop = $r->getProperty('schema');
    $prop->setAccessible(true);
    expect($prop->getValue($graph))->toBe($schema);

    $counts = $graph->replace('demo', '1', [], [], []);
    expect($counts)->toBe(['sources' => 0, 'nodes' => 0, 'edges' => 0])
        ->and(is_file($path))->toBeTrue();

    $pdo = new PDO('sqlite:'.$path);
    expect((new GraphSchema)->recordedVersion($pdo))->toBe(GraphSchema::VERSION);

    @unlink($path);
});

it('resolves Graph from the container with GraphSchema', function () {
    $graph = app(Graph::class);
    $r = new ReflectionClass($graph);
    $prop = $r->getProperty('schema');
    $prop->setAccessible(true);
    expect($prop->getValue($graph))->toBeInstanceOf(GraphSchema::class);
});
