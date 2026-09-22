<?php

use Sifrious\Molly\Actions\BootstrapProjectKnowledgeGraphs;
use Sifrious\Molly\Actions\IndexProjectGraph;
use Sifrious\Molly\Knowledge\ComposerLock;
use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphCache;
use Sifrious\Molly\Knowledge\LaravelQueueGraph;
use Sifrious\Molly\Knowledge\NativePhpGraph;

it('injects bootstrap collaborators and an ordered Laravel graph builder list', function () {
    $action = app(BootstrapProjectKnowledgeGraphs::class);
    $r = new ReflectionClass($action);

    foreach ([
        'lock' => ComposerLock::class,
        'cache' => GraphCache::class,
        'graph' => Graph::class,
        'nativePhpGraph' => NativePhpGraph::class,
        'indexProjectGraph' => IndexProjectGraph::class,
    ] as $name => $class) {
        $prop = $r->getProperty($name);
        $prop->setAccessible(true);
        expect($prop->getValue($action))->toBeInstanceOf($class);
    }

    $builders = $r->getProperty('laravelGraphs');
    $builders->setAccessible(true);
    $list = $builders->getValue($action);

    expect($list)->toBeArray()->and($list)->not->toBeEmpty()
        ->and($list[0])->toBeInstanceOf(LaravelQueueGraph::class)
        ->and(count($list))->toBe(7);
});
