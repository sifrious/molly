<?php

use Sifrious\Molly\Actions\BootstrapProjectKnowledgeGraphs;
use Sifrious\Molly\Actions\IndexProjectGraph;
use Sifrious\Molly\Knowledge\ComposerLock;
use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphCache;
use Sifrious\Molly\Knowledge\GraphEdge;
use Sifrious\Molly\Knowledge\GraphManifest;
use Sifrious\Molly\Knowledge\GraphNode;
use Sifrious\Molly\Knowledge\GraphSnapshot;
use Sifrious\Molly\Knowledge\GraphSource;
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

it('consumes GraphSnapshot for build merge and cache round-trip', function () {
    $action = app(BootstrapProjectKnowledgeGraphs::class);
    $r = new ReflectionClass($action);

    $build = $r->getMethod('buildLaravelSnapshot');
    $build->setAccessible(true);
    $snapshot = $build->invoke($action, '12');
    expect($snapshot)->toBeInstanceOf(GraphSnapshot::class)
        ->and($snapshot->namespace)->toBe('laravel')
        ->and($snapshot->version)->toBe('12');

    $payload = $r->getMethod('cachePayload');
    $payload->setAccessible(true);
    $cached = $payload->invoke($action, $snapshot, '12');
    expect($cached)->toHaveKeys(['graph_version_key', 'sources', 'nodes', 'edges'])
        ->and($cached['graph_version_key'])->toBe('12');

    if ($snapshot->nodes !== []) {
        expect($cached['nodes'][0])->toHaveKey('source_ids')
            ->and($cached['nodes'][0])->not->toHaveKey('sourceIds');
    }

    $fromCache = $r->getMethod('snapshotFromCache');
    $fromCache->setAccessible(true);
    $restored = $fromCache->invoke($action, 'laravel', '12', $cached);
    expect($restored)->toBeInstanceOf(GraphSnapshot::class)
        ->and($restored->toArray())->toBe($snapshot->toArray());

    // Legacy cache rows used sourceIds; normalize into GraphSnapshot source_ids.
    $source = new GraphSource('laravel', '12', 'doc', 'legacy', 'Legacy');
    $node = new GraphNode('laravel', '12', 'symbol', 'legacy-node', 'Legacy node', [$source->id()]);
    $edge = new GraphEdge('laravel', '12', 'related', $node->id(), $node->id(), [$source->id()]);
    $legacy = [
        'sources' => [[
            'namespace' => 'laravel',
            'version' => '12',
            'type' => 'doc',
            'key' => 'legacy',
            'title' => 'Legacy',
            'location' => null,
            'revision' => null,
            'digest' => null,
            'metadata' => [],
        ]],
        'nodes' => [[
            'namespace' => 'laravel',
            'version' => '12',
            'type' => 'symbol',
            'key' => 'legacy-node',
            'label' => 'Legacy node',
            'sourceIds' => [$source->id()],
            'metadata' => [],
        ]],
        'edges' => [[
            'namespace' => 'laravel',
            'version' => '12',
            'relation' => 'related',
            'from' => $node->id(),
            'to' => $node->id(),
            'sourceIds' => [$source->id()],
            'metadata' => [],
        ]],
    ];
    $legacySnap = $fromCache->invoke($action, 'laravel', '12', $legacy);
    expect($legacySnap->nodes)->toHaveCount(1)
        ->and($legacySnap->nodes[0]->sourceIds)->toBe([$source->id()])
        ->and($legacySnap->edges)->toHaveCount(1);
});

it('delegates manifest persistence and retry merging to GraphManifest', function () {
    $action = app(BootstrapProjectKnowledgeGraphs::class);
    $r = new ReflectionClass($action);

    $root = realpath(sys_get_temp_dir()).'/molly-manifest-'.uniqid('', true);
    mkdir($root.'/.molly/graphs', 0700, true);

    $lock = [
        'packages' => ['laravel/framework' => '12.0.0'],
        'laravel' => '12.0.0',
        'laravel_major' => '12',
        'lock_path' => $root.'/composer.lock',
        'lock_hash' => 'abc',
    ];
    $unit = [
        'id' => 'laravel:laravel/framework',
        'package' => 'laravel/framework',
        'namespace' => 'laravel',
        'exact_version' => '12.0.0',
        'graph_version_key' => '12',
        'status' => 'ready',
        'source' => 'built',
        'error' => null,
    ];

    $write = $r->getMethod('writeManifest');
    $write->setAccessible(true);
    $path = $write->invoke($action, $root, $lock, [$unit]);
    expect($path)->toBe(GraphManifest::pathFor($root))
        ->and(is_file($path))->toBeTrue();

    $loaded = GraphManifest::load($root);
    expect($loaded->schemaVersion)->toBe(GraphManifest::SCHEMA_VERSION)
        ->and($loaded->laravelExact)->toBe('12.0.0')
        ->and($loaded->laravelMajor)->toBe(12)
        ->and($loaded->units)->toHaveCount(1)
        ->and($loaded->allReady())->toBeTrue();

    $retry = $unit;
    $retry['source'] = 'cache';
    $merge = $r->getMethod('mergeUnits');
    $merge->setAccessible(true);
    $merged = $merge->invoke($action, $root, [
        ['id' => 'laravel:laravel/framework', 'status' => 'skipped'],
        $retry,
    ]);
    expect($merged)->toHaveCount(1)
        ->and($merged[0]['source'])->toBe('cache');

    $ready = $r->getMethod('allReady');
    $ready->setAccessible(true);
    expect($ready->invoke($action, $merged))->toBeTrue()
        ->and($ready->invoke($action, [['id' => 'x', 'status' => 'failed']]))->toBeFalse();

    // cleanup
    @unlink($path);
    @rmdir($root.'/.molly/graphs');
    @rmdir($root.'/.molly');
    @rmdir($root);
});
