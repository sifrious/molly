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

it('proves graph cache failure and exact-version behavior after atomic writes', function (): void {
    $source = new GraphSource('laravel', '12', 'documentation', 'queues', 'Queues', null, null, null);
    $version = new GraphNode('laravel', '12', 'version', 'laravel:12', 'Laravel 12', [$source->id()]);
    $snapshot = new GraphSnapshot('laravel', '12', [$source], [$version], []);
    $payload = $snapshot->toArray();
    $entry = [
        'graph_version_key' => '12',
        'sources' => $payload['sources'],
        'nodes' => $payload['nodes'],
        'edges' => $payload['edges'],
    ];

    $this->cache->put('laravel', 'laravel/framework', '12.0.0', $entry);
    $path = $this->cache->path('laravel', 'laravel/framework', '12.0.0');
    $valid = File::get($path);
    $first = $this->cache->get('laravel', 'laravel/framework', '12.0.0');

    expect($first)->not->toBeNull()
        ->and($first['exact_version'])->toBe('12.0.0')
        ->and($first['schema_version'])->toBe(GraphCache::SCHEMA_VERSION)
        ->and($first['nodes'])->toEqual($payload['nodes'])
        ->and($first['sources'])->toEqual($payload['sources'])
        ->and($first['edges'])->toEqual($payload['edges'])
        ->and(decoct(fileperms($path) & 0777))->toBe('600')
        ->and(decoct(fileperms(dirname($path)) & 0777))->toBe('700')
        ->and($this->cache->get('laravel', 'laravel/framework', '12.0.1'))->toBeNull()
        ->and($this->cache->get('laravel', 'laravel/framework', '11.0.0'))->toBeNull()
        ->and($this->cache->get('nativephp', 'laravel/framework', '12.0.0'))->toBeNull();

    File::put($path, '{not-json');
    expect($this->cache->get('laravel', 'laravel/framework', '12.0.0'))->toBeNull();

    File::put($path, $valid);
    $decoded = json_decode($valid, true, 512, JSON_THROW_ON_ERROR);
    $decoded['schema_version'] = GraphCache::SCHEMA_VERSION + 1;
    File::put($path, json_encode($decoded, JSON_THROW_ON_ERROR));
    expect($this->cache->get('laravel', 'laravel/framework', '12.0.0'))->toBeNull();

    $decoded['schema_version'] = GraphCache::SCHEMA_VERSION;
    $decoded['package'] = 'other/package';
    File::put($path, json_encode($decoded, JSON_THROW_ON_ERROR));
    expect($this->cache->get('laravel', 'laravel/framework', '12.0.0'))->toBeNull();

    File::put($path, $valid);
    expect($this->cache->get('laravel', 'laravel/framework', '12.0.0'))->not->toBeNull();

    $before = File::get($path);
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
    ]))->toThrow(RuntimeException::class, 'KNOWLEDGE_SNAPSHOT_INVALID');

    expect(File::get($path))->toBe($before)
        ->and($this->cache->get('laravel', 'laravel/framework', '12.0.0')['nodes'])->toEqual($payload['nodes'])
        ->and(glob(dirname($path).'/.graph-cache-*') ?: [])->toBe([])
        ->and(collect(scandir(dirname($path)))->filter(fn ($name) => str_ends_with($name, '.tmp'))->values()->all())->toBe([]);

    // Partial stage file must not become a usable cache entry.
    $temp = dirname($path).'/.graph-cache-partial.tmp';
    File::put($temp, substr($valid, 0, 20));
    expect($this->cache->get('laravel', 'laravel/framework', '12.0.0')['nodes'])->toEqual($payload['nodes'])
        ->and(is_file($path))->toBeTrue()
        ->and(File::get($path))->toBe($before);
    @unlink($temp);
});
