<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Knowledge\ComposerLock;
use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphCache;
use Sifrious\Molly\Knowledge\GraphEdge;
use Sifrious\Molly\Knowledge\GraphManifest;
use Sifrious\Molly\Knowledge\GraphNode;
use Sifrious\Molly\Knowledge\GraphSnapshot;
use Sifrious\Molly\Knowledge\GraphSource;
use Sifrious\Molly\Knowledge\NativePhpGraph;

/**
 * Build version-pinned knowledge graphs during project initialization.
 *
 * Laravel framework graph is mandatory. Supported dependency graphs are attempted
 * only for packages present in composer.lock at their exact installed versions.
 * Cache reuse requires an exact version match — never a quieter neighboring version.
 */
final class BootstrapProjectKnowledgeGraphs
{
    public const MANIFEST_SCHEMA = GraphManifest::SCHEMA_VERSION;

    /** @var array<string, string> composer package => graph namespace */
    public const SUPPORTED_DEPENDENCIES = [
        'nativephp/desktop' => 'nativephp',
        'nativephp/mobile' => 'nativephp',
    ];

    /**
     * @param  list<object{build(string): array{sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>}}>  $laravelGraphs
     */
    public function __construct(
        private ComposerLock $lock,
        private GraphCache $cache,
        private Graph $graph,
        private NativePhpGraph $nativePhpGraph,
        private IndexProjectGraph $indexProjectGraph,
        private array $laravelGraphs,
    ) {}

    /**
     * @param  (callable(string, string): void)|null  $progress
     * @return array{ok: bool, manifest_path: string, units: list<array<string, mixed>>, laravel_exact: string}
     */
    public function handle(string $projectRoot, ?callable $progress = null, ?string $onlyUnit = null): array
    {
        $progress ??= static function (string $step, string $message): void {};
        $root = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $resolved = realpath($root);
        if (is_string($resolved)) {
            $root = $resolved;
        }
        $lock = $this->lock->read($root);

        if ($lock['laravel'] === null || $lock['laravel_major'] === null) {
            throw new RuntimeException('LARAVEL_VERSION_MISSING: composer.lock must include laravel/framework so Molly can pin the Laravel graph.');
        }

        $progress('lockfile', 'Read laravel/framework '.$lock['laravel'].' (major '.$lock['laravel_major'].')');

        $units = [];
        $laravelUnit = $this->bootstrapLaravel($lock, $progress, $onlyUnit);
        $units[] = $laravelUnit;

        if ($laravelUnit['status'] === 'failed') {
            $this->writeManifest($root, $lock, $units);
            throw new RuntimeException('LARAVEL_GRAPH_REQUIRED: '.$laravelUnit['error']);
        }

        foreach (self::SUPPORTED_DEPENDENCIES as $package => $namespace) {
            if (! isset($lock['packages'][$package])) {
                continue;
            }
            $unitId = $namespace.':'.$package;
            if ($onlyUnit !== null && $onlyUnit !== $unitId) {
                continue;
            }
            $units[] = $this->bootstrapSupportedDependency(
                $namespace,
                $package,
                $lock['packages'][$package],
                $progress,
            );
        }

        if ($onlyUnit === null || $onlyUnit === 'project:workspace') {
            $units[] = $this->bootstrapProjectGraph($root, $progress);
        }

        if ($onlyUnit !== null) {
            $units = $this->mergeUnits($root, $units);
        }

        $manifestPath = $this->writeManifest($root, $lock, $units);
        $progress('manifest', 'Wrote graph provenance to '.$manifestPath);

        return [
            'ok' => $this->allReady($units),
            'manifest_path' => $manifestPath,
            'units' => $units,
            'laravel_exact' => $lock['laravel'],
        ];
    }

    /**
     * @param  array{packages: array<string, string>, laravel: string, laravel_major: string, lock_path: string, lock_hash: ?string}  $lock
     * @param  callable(string, string): void  $progress
     * @return array<string, mixed>
     */
    private function bootstrapLaravel(array $lock, callable $progress, ?string $onlyUnit): array
    {
        $unitId = 'laravel:laravel/framework';
        if ($onlyUnit !== null && $onlyUnit !== $unitId) {
            return [
                'id' => $unitId,
                'package' => 'laravel/framework',
                'namespace' => 'laravel',
                'exact_version' => $lock['laravel'],
                'graph_version_key' => $lock['laravel_major'],
                'status' => 'skipped',
                'source' => null,
                'error' => null,
            ];
        }

        $progress('laravel', 'Resolving Laravel graph for exact '.$lock['laravel']);

        try {
            $cached = $this->cache->get('laravel', 'laravel/framework', $lock['laravel']);
            if ($cached !== null) {
                $snapshot = $this->snapshotFromCache('laravel', $lock['laravel_major'], $cached);
                $counts = $this->graph->replace(
                    'laravel',
                    $lock['laravel_major'],
                    $snapshot->sources,
                    $snapshot->nodes,
                    $snapshot->edges,
                );
                $source = 'cache';
                $progress('laravel', 'Downloaded exact-version cache for laravel/framework '.$lock['laravel']);
            } else {
                $snapshot = $this->buildLaravelSnapshot($lock['laravel_major']);
                $counts = $this->graph->replace(
                    'laravel',
                    $lock['laravel_major'],
                    $snapshot->sources,
                    $snapshot->nodes,
                    $snapshot->edges,
                );
                $this->cache->put(
                    'laravel',
                    'laravel/framework',
                    $lock['laravel'],
                    $this->cachePayload($snapshot, $lock['laravel_major']),
                );
                $source = 'built';
                $progress('laravel', 'Built Laravel '.$lock['laravel_major'].' graph ('.$counts['nodes'].' nodes)');
            }

            return [
                'id' => $unitId,
                'package' => 'laravel/framework',
                'namespace' => 'laravel',
                'exact_version' => $lock['laravel'],
                'graph_version_key' => $lock['laravel_major'],
                'status' => 'ready',
                'source' => $source,
                'counts' => $counts,
                'error' => null,
                'schema_version' => self::MANIFEST_SCHEMA,
                'ingested_at' => gmdate('c'),
            ];
        } catch (\Throwable $exception) {
            return [
                'id' => $unitId,
                'package' => 'laravel/framework',
                'namespace' => 'laravel',
                'exact_version' => $lock['laravel'],
                'graph_version_key' => $lock['laravel_major'],
                'status' => 'failed',
                'source' => null,
                'error' => $exception->getMessage(),
                'schema_version' => self::MANIFEST_SCHEMA,
                'ingested_at' => gmdate('c'),
            ];
        }
    }

    /**
     * @param  callable(string, string): void  $progress
     * @return array<string, mixed>
     */
    private function bootstrapSupportedDependency(
        string $namespace,
        string $package,
        string $exactVersion,
        callable $progress,
    ): array {
        $unitId = $namespace.':'.$package;
        $progress($namespace, 'Resolving '.$package.' @ '.$exactVersion);

        try {
            $cached = $this->cache->get($namespace, $package, $exactVersion);
            if ($cached !== null) {
                $graphVersion = (string) ($cached['graph_version_key'] ?? $exactVersion);
                $snapshot = $this->snapshotFromCache($namespace, $graphVersion, $cached);
                $counts = $this->graph->replace(
                    $namespace,
                    $graphVersion,
                    $snapshot->sources,
                    $snapshot->nodes,
                    $snapshot->edges,
                );
                $progress($namespace, 'Downloaded exact-version cache for '.$package.' '.$exactVersion);

                return [
                    'id' => $unitId,
                    'package' => $package,
                    'namespace' => $namespace,
                    'exact_version' => $exactVersion,
                    'graph_version_key' => $graphVersion,
                    'status' => 'ready',
                    'source' => 'cache',
                    'counts' => $counts,
                    'error' => null,
                    'schema_version' => self::MANIFEST_SCHEMA,
                    'ingested_at' => gmdate('c'),
                ];
            }

            $track = match ($package) {
                'nativephp/desktop' => 'desktop',
                'nativephp/mobile' => 'mobile',
                default => throw new RuntimeException('GRAPH_UNSUPPORTED: No builder for '.$package.'.'),
            };

            $built = $this->nativePhpGraph->build($track);
            $snapshot = new GraphSnapshot(
                $namespace,
                $built['version'],
                $built['sources'],
                $built['nodes'],
                $built['edges'],
            );
            $counts = $this->graph->replace(
                $namespace,
                $built['version'],
                $snapshot->sources,
                $snapshot->nodes,
                $snapshot->edges,
            );
            $this->cache->put(
                $namespace,
                $package,
                $exactVersion,
                $this->cachePayload($snapshot, $built['version']),
            );
            $progress($namespace, 'Built '.$package.' graph for '.$exactVersion);

            return [
                'id' => $unitId,
                'package' => $package,
                'namespace' => $namespace,
                'exact_version' => $exactVersion,
                'graph_version_key' => $built['version'],
                'status' => 'ready',
                'source' => 'built',
                'counts' => $counts,
                'error' => null,
                'schema_version' => self::MANIFEST_SCHEMA,
                'ingested_at' => gmdate('c'),
            ];
        } catch (\Throwable $exception) {
            return [
                'id' => $unitId,
                'package' => $package,
                'namespace' => $namespace,
                'exact_version' => $exactVersion,
                'graph_version_key' => $exactVersion,
                'status' => 'failed',
                'source' => null,
                'error' => $exception->getMessage(),
                'schema_version' => self::MANIFEST_SCHEMA,
                'ingested_at' => gmdate('c'),
            ];
        }
    }

    /**
     * @param  callable(string, string): void  $progress
     * @return array<string, mixed>
     */
    private function bootstrapProjectGraph(string $root, callable $progress): array
    {
        $unitId = 'project:workspace';
        $progress('project', 'Indexing project workspace graph');
        try {
            $result = $this->indexProjectGraph->handle($root);

            return [
                'id' => $unitId,
                'package' => 'project',
                'namespace' => 'project',
                'exact_version' => $result['version'] ?? 'workspace',
                'graph_version_key' => $result['version'] ?? 'workspace',
                'status' => 'ready',
                'source' => 'built',
                'counts' => [
                    'sources' => $result['sources'] ?? 0,
                    'nodes' => $result['nodes'] ?? 0,
                    'edges' => $result['edges'] ?? 0,
                ],
                'error' => null,
                'schema_version' => self::MANIFEST_SCHEMA,
                'ingested_at' => gmdate('c'),
            ];
        } catch (\Throwable $exception) {
            return [
                'id' => $unitId,
                'package' => 'project',
                'namespace' => 'project',
                'exact_version' => 'workspace',
                'graph_version_key' => 'workspace',
                'status' => 'failed',
                'source' => null,
                'error' => $exception->getMessage(),
                'schema_version' => self::MANIFEST_SCHEMA,
                'ingested_at' => gmdate('c'),
            ];
        }
    }

    private function buildLaravelSnapshot(string $major): GraphSnapshot
    {
        $sources = [];
        $nodes = [];
        $edges = [];
        foreach ($this->laravelGraphs as $builder) {
            $part = $builder->build($major);
            foreach ($part['sources'] as $source) {
                $sources[$source->id()] = $source;
            }
            foreach ($part['nodes'] as $node) {
                $nodes[$node->id()] = $node;
            }
            foreach ($part['edges'] as $edge) {
                $edges[$edge->id()] = $edge;
            }
        }

        return new GraphSnapshot(
            'laravel',
            $major,
            array_values($sources),
            array_values($nodes),
            array_values($edges),
        );
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    private function snapshotFromCache(string $namespace, string $version, array $cached): GraphSnapshot
    {
        return GraphSnapshot::fromArray([
            'namespace' => $namespace,
            'version' => $version,
            'sources' => array_values(array_map(
                fn (mixed $row): array => is_array($row) ? $row : [],
                $cached['sources'] ?? [],
            )),
            'nodes' => array_values(array_map(
                fn (mixed $row): array => is_array($row) ? $this->normalizeSourceIds($row) : [],
                $cached['nodes'] ?? [],
            )),
            'edges' => array_values(array_map(
                fn (mixed $row): array => is_array($row) ? $this->normalizeSourceIds($row) : [],
                $cached['edges'] ?? [],
            )),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeSourceIds(array $row): array
    {
        if (isset($row['sourceIds']) && ! isset($row['source_ids'])) {
            $row['source_ids'] = $row['sourceIds'];
            unset($row['sourceIds']);
        }

        return $row;
    }

    /**
     * @return array{graph_version_key: string, sources: list<array<string, mixed>>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function cachePayload(GraphSnapshot $snapshot, string $graphVersionKey): array
    {
        $array = $snapshot->toArray();

        return [
            'graph_version_key' => $graphVersionKey,
            'sources' => $array['sources'],
            'nodes' => $array['nodes'],
            'edges' => $array['edges'],
        ];
    }

    /**
     * @param  array{packages: array<string, string>, laravel: string, laravel_major: string, lock_path: string, lock_hash: ?string}  $lock
     * @param  list<array<string, mixed>>  $units
     */
    private function writeManifest(string $root, array $lock, array $units): string
    {
        return $this->manifest($root, $lock, $units)->write($root);
    }

    /**
     * @param  list<array<string, mixed>>  $fresh
     * @return list<array<string, mixed>>
     */
    private function mergeUnits(string $root, array $fresh): array
    {
        $manifest = GraphManifest::load($root);
        foreach ($fresh as $unit) {
            if (($unit['status'] ?? null) === 'skipped') {
                continue;
            }
            $manifest = $manifest->withReplacedUnit($unit);
        }

        return $manifest->units;
    }

    /** @param  list<array<string, mixed>>  $units */
    private function allReady(array $units): bool
    {
        return new GraphManifest(
            schemaVersion: GraphManifest::SCHEMA_VERSION,
            projectPath: null,
            lockPath: null,
            lockHash: null,
            laravelExact: null,
            laravelMajor: null,
            packages: [],
            units: $units,
            updatedAt: null,
            path: null,
        )->allReady();
    }

    /**
     * @param  array{packages: array<string, string>, laravel: string, laravel_major: string, lock_path: string, lock_hash: ?string}  $lock
     * @param  list<array<string, mixed>>  $units
     */
    private function manifest(string $root, array $lock, array $units): GraphManifest
    {
        return new GraphManifest(
            schemaVersion: GraphManifest::SCHEMA_VERSION,
            projectPath: $root,
            lockPath: $lock['lock_path'],
            lockHash: $lock['lock_hash'],
            laravelExact: $lock['laravel'],
            laravelMajor: (int) $lock['laravel_major'],
            packages: $lock['packages'],
            units: $units,
            updatedAt: gmdate('c'),
            path: GraphManifest::pathFor($root),
        );
    }
}
