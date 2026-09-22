<?php

use Illuminate\Support\Facades\File;
use Sifrious\Molly\Knowledge\GraphCache;
use Sifrious\Molly\Knowledge\GraphNode;
use Sifrious\Molly\Knowledge\GraphSnapshot;
use Sifrious\Molly\Knowledge\GraphSource;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/molly-graph-cache-'.uniqid();
    File::ensureDirectoryExists($this->root);
    $this->cache = new GraphCache($this->root);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('round-trips a GraphSnapshot-validated cache payload', function (): void {
    $source = new GraphSource('laravel', '12', 'documentation', 'queues', 'Queues', null, null, null);
    $version = new GraphNode('laravel', '12', 'version', 'laravel:12', 'Laravel 12', [$source->id()]);
    $snapshot = new GraphSnapshot('laravel', '12', [$source], [$version], []);
    $payload = $snapshot->toArray();

    $this->cache->put('laravel', 'laravel/framework', '12.0.0', [
        'graph_version_key' => '12',
        'sources' => $payload['sources'],
        'nodes' => $payload['nodes'],
        'edges' => $payload['edges'],
    ]);

    $cached = $this->cache->get('laravel', 'laravel/framework', '12.0.0');
    expect($cached)->not->toBeNull()
        ->and($cached['exact_version'])->toBe('12.0.0')
        ->and($cached['graph_version_key'])->toBe('12')
        ->and($cached['nodes'])->toHaveCount(1);
});

it('treats malformed graph records as a cache miss', function (): void {
    $path = $this->cache->path('laravel', 'laravel/framework', '12.0.0');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode([
        'schema_version' => GraphCache::SCHEMA_VERSION,
        'namespace' => 'laravel',
        'package' => 'laravel/framework',
        'exact_version' => '12.0.0',
        'graph_version_key' => '12',
        'built_at' => gmdate('c'),
        'sources' => [],
        'nodes' => [[
            'namespace' => 'laravel',
            'version' => '12',
            'type' => 'concept',
            'key' => 'queue',
            'label' => 'Queue',
            'source_ids' => ['missing-source'],
        ]],
        'edges' => [],
    ], JSON_THROW_ON_ERROR));

    expect($this->cache->get('laravel', 'laravel/framework', '12.0.0'))->toBeNull();
});

it('treats mixed-scope graph records as a cache miss', function (): void {
    $path = $this->cache->path('laravel', 'laravel/framework', '12.0.0');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, json_encode([
        'schema_version' => GraphCache::SCHEMA_VERSION,
        'namespace' => 'laravel',
        'package' => 'laravel/framework',
        'exact_version' => '12.0.0',
        'graph_version_key' => '12',
        'built_at' => gmdate('c'),
        'sources' => [[
            'namespace' => 'laravel',
            'version' => '11',
            'type' => 'documentation',
            'key' => 'queues',
            'title' => 'Queues',
        ]],
        'nodes' => [],
        'edges' => [],
    ], JSON_THROW_ON_ERROR));

    expect($this->cache->get('laravel', 'laravel/framework', '12.0.0'))->toBeNull();
});

it('never hits a different exact version', function (): void {
    $source = new GraphSource('laravel', '12', 'documentation', 'queues', 'Queues', null, null, null);
    $version = new GraphNode('laravel', '12', 'version', 'laravel:12', 'Laravel 12', [$source->id()]);
    $snapshot = new GraphSnapshot('laravel', '12', [$source], [$version], []);
    $payload = $snapshot->toArray();

    $this->cache->put('laravel', 'laravel/framework', '12.0.0', [
        'graph_version_key' => '12',
        'sources' => $payload['sources'],
        'nodes' => $payload['nodes'],
        'edges' => $payload['edges'],
    ]);

    expect($this->cache->get('laravel', 'laravel/framework', '12.0.1'))->toBeNull();
});

it('writes cache entries atomically and privately', function (): void {
    $source = new GraphSource('laravel', '12', 'documentation', 'queues', 'Queues', null, null, null);
    $version = new GraphNode('laravel', '12', 'version', 'laravel:12', 'Laravel 12', [$source->id()]);
    $snapshot = new GraphSnapshot('laravel', '12', [$source], [$version], []);
    $payload = $snapshot->toArray();

    $this->cache->put('laravel', 'laravel/framework', '12.0.0', [
        'graph_version_key' => '12',
        'sources' => $payload['sources'],
        'nodes' => $payload['nodes'],
        'edges' => $payload['edges'],
    ]);

    $path = $this->cache->path('laravel', 'laravel/framework', '12.0.0');
    expect(is_file($path))->toBeTrue()
        ->and(decoct(fileperms($path) & 0777))->toBe('600')
        ->and(glob(dirname($path).'/.graph-cache-*') ?: [])->toBe([]);
});

it('preserves a prior valid entry when a later put fails hydration', function (): void {
    $source = new GraphSource('laravel', '12', 'documentation', 'queues', 'Queues', null, null, null);
    $version = new GraphNode('laravel', '12', 'version', 'laravel:12', 'Laravel 12', [$source->id()]);
    $snapshot = new GraphSnapshot('laravel', '12', [$source], [$version], []);
    $payload = $snapshot->toArray();

    $this->cache->put('laravel', 'laravel/framework', '12.0.0', [
        'graph_version_key' => '12',
        'sources' => $payload['sources'],
        'nodes' => $payload['nodes'],
        'edges' => $payload['edges'],
    ]);

    $before = File::get($this->cache->path('laravel', 'laravel/framework', '12.0.0'));

    expect(fn () => $this->cache->put('laravel', 'laravel/framework', '12.0.0', [
        'graph_version_key' => '12',
        'sources' => [],
        'nodes' => [[
            'namespace' => 'laravel',
            'version' => '12',
            'type' => 'concept',
            'key' => 'queue',
            'label' => 'Queue',
            'source_ids' => ['missing-source'],
        ]],
        'edges' => [],
    ]))->toThrow(RuntimeException::class);

    $path = $this->cache->path('laravel', 'laravel/framework', '12.0.0');
    expect(File::get($path))->toBe($before)
        ->and($this->cache->get('laravel', 'laravel/framework', '12.0.0'))->not->toBeNull()
        ->and(glob(dirname($path).'/.graph-cache-*') ?: [])->toBe([]);
});
