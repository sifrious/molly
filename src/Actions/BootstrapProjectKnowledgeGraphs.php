<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Sifrious\Molly\Knowledge\ComposerLock;
use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphCache;
use Sifrious\Molly\Knowledge\GraphEdge;
use Sifrious\Molly\Knowledge\GraphNode;
use Sifrious\Molly\Knowledge\GraphSource;
use Sifrious\Molly\Knowledge\LaravelContainerGraph;
use Sifrious\Molly\Knowledge\LaravelEloquentGraph;
use Sifrious\Molly\Knowledge\LaravelEventsGraph;
use Sifrious\Molly\Knowledge\LaravelQueueGraph;
use Sifrious\Molly\Knowledge\LaravelRoutingGraph;
use Sifrious\Molly\Knowledge\LaravelTestingGraph;
use Sifrious\Molly\Knowledge\LaravelValidationGraph;
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
    public const MANIFEST_SCHEMA = 1;

    /** @var array<string, string> composer package => graph namespace */
    public const SUPPORTED_DEPENDENCIES = [
        'nativephp/desktop' => 'nativephp',
        'nativephp/mobile' => 'nativephp',
    ];

    public function __construct(
        private ?ComposerLock $lock = null,
        private ?GraphCache $cache = null,
        private ?Graph $graph = null,
    ) {
        $this->lock ??= new ComposerLock;
        $this->cache ??= new GraphCache;
        $this->graph ??= app(Graph::class);
    }

    /**
     * @param  (callable(string, string): void)|null  $progress
     * @return array{ok: bool, manifest_path: string, units: list<array<string, mixed>>, laravel_exact: string}
     */
    public function handle(string $projectRoot, ?callable $progress = null, ?string $onlyUnit = null): array
    {
        $progress ??= static function (string $step, string $message): void {};
        $root = rtrim(str_replace('\\', '/', $projectRoot), '/');
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
                $hydrated = $this->hydrateSnapshot($cached);
                $counts = $this->graph->replace(
                    'laravel',
                    $lock['laravel_major'],
                    $hydrated['sources'],
                    $hydrated['nodes'],
                    $hydrated['edges'],
                );
                $source = 'cache';
                $progress('laravel', 'Downloaded exact-version cache for laravel/framework '.$lock['laravel']);
            } else {
                $snapshot = $this->buildLaravelSnapshot($lock['laravel_major']);
                $counts = $this->graph->replace(
                    'laravel',
                    $lock['laravel_major'],
                    $snapshot['sources'],
                    $snapshot['nodes'],
                    $snapshot['edges'],
                );
                $this->cache->put('laravel', 'laravel/framework', $lock['laravel'], [
                    'graph_version_key' => $lock['laravel_major'],
                    'sources' => array_map(fn ($s) => $this->sourceToArray($s), $snapshot['sources']),
                    'nodes' => array_map(fn ($n) => $this->nodeToArray($n), $snapshot['nodes']),
                    'edges' => array_map(fn ($e) => $this->edgeToArray($e), $snapshot['edges']),
                ]);
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
                $hydrated = $this->hydrateSnapshot($cached);
                $graphVersion = (string) ($cached['graph_version_key'] ?? $exactVersion);
                $counts = $this->graph->replace(
                    $namespace,
                    $graphVersion,
                    $hydrated['sources'],
                    $hydrated['nodes'],
                    $hydrated['edges'],
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

            $built = app(NativePhpGraph::class)->build($track);
            $counts = $this->graph->replace(
                $namespace,
                $built['version'],
                $built['sources'],
                $built['nodes'],
                $built['edges'],
            );
            $this->cache->put($namespace, $package, $exactVersion, [
                'graph_version_key' => $built['version'],
                'sources' => array_map(fn ($s) => $this->sourceToArray($s), $built['sources']),
                'nodes' => array_map(fn ($n) => $this->nodeToArray($n), $built['nodes']),
                'edges' => array_map(fn ($e) => $this->edgeToArray($e), $built['edges']),
            ]);
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
            $result = app(IndexProjectGraph::class)->handle($root);

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

    /** @return array{sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>} */
    private function buildLaravelSnapshot(string $major): array
    {
        $parts = [
            app(LaravelQueueGraph::class)->build($major),
            app(LaravelRoutingGraph::class)->build($major),
            app(LaravelTestingGraph::class)->build($major),
            app(LaravelValidationGraph::class)->build($major),
            app(LaravelContainerGraph::class)->build($major),
            app(LaravelEloquentGraph::class)->build($major),
            app(LaravelEventsGraph::class)->build($major),
        ];

        $sources = [];
        $nodes = [];
        $edges = [];
        foreach ($parts as $part) {
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

        return [
            'sources' => array_values($sources),
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
        ];
    }

    /**
     * @param  array{sources?: list<array<string, mixed>>, nodes?: list<array<string, mixed>>, edges?: list<array<string, mixed>>}  $cached
     * @return array{sources: list<GraphSource>, nodes: list<GraphNode>, edges: list<GraphEdge>}
     */
    private function hydrateSnapshot(array $cached): array
    {
        $sources = [];
        foreach ($cached['sources'] ?? [] as $row) {
            $sources[] = new GraphSource(
                (string) $row['namespace'],
                (string) $row['version'],
                (string) $row['type'],
                (string) $row['key'],
                (string) $row['title'],
                isset($row['location']) && is_string($row['location']) ? $row['location'] : null,
                isset($row['revision']) && is_string($row['revision']) ? $row['revision'] : null,
                isset($row['digest']) && is_string($row['digest']) ? $row['digest'] : null,
                is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
            );
        }

        $nodes = [];
        foreach ($cached['nodes'] ?? [] as $row) {
            $nodes[] = new GraphNode(
                (string) $row['namespace'],
                (string) $row['version'],
                (string) $row['type'],
                (string) $row['key'],
                (string) $row['label'],
                array_values(array_map('strval', $row['sourceIds'] ?? [])),
                is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
            );
        }

        $edges = [];
        foreach ($cached['edges'] ?? [] as $row) {
            $edges[] = new GraphEdge(
                (string) $row['namespace'],
                (string) $row['version'],
                (string) $row['relation'],
                (string) $row['from'],
                (string) $row['to'],
                array_values(array_map('strval', $row['sourceIds'] ?? [])),
                is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
            );
        }

        return ['sources' => $sources, 'nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @param  array{packages: array<string, string>, laravel: string, laravel_major: string, lock_path: string, lock_hash: ?string}  $lock
     * @param  list<array<string, mixed>>  $units
     */
    private function writeManifest(string $root, array $lock, array $units): string
    {
        $directory = $root.'/.molly/graphs';
        File::ensureDirectoryExists($directory, 0700);
        $path = $directory.'/manifest.json';
        $payload = [
            'schema_version' => self::MANIFEST_SCHEMA,
            'project_path' => $root,
            'lock_path' => $lock['lock_path'],
            'lock_hash' => $lock['lock_hash'],
            'laravel_exact' => $lock['laravel'],
            'laravel_major' => $lock['laravel_major'],
            'packages' => $lock['packages'],
            'units' => $units,
            'updated_at' => gmdate('c'),
        ];
        $mask = umask(0077);
        try {
            File::put($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        } finally {
            umask($mask);
        }

        return $path;
    }

    /**
     * @param  list<array<string, mixed>>  $fresh
     * @return list<array<string, mixed>>
     */
    private function mergeUnits(string $root, array $fresh): array
    {
        $path = $root.'/.molly/graphs/manifest.json';
        if (! is_file($path)) {
            return array_values(array_filter($fresh, fn ($unit) => ($unit['status'] ?? null) !== 'skipped'));
        }

        try {
            /** @var array<string, mixed> $existing */
            $existing = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return array_values(array_filter($fresh, fn ($unit) => ($unit['status'] ?? null) !== 'skipped'));
        }

        $byId = [];
        foreach ($existing['units'] ?? [] as $unit) {
            if (is_array($unit) && isset($unit['id']) && is_string($unit['id'])) {
                $byId[$unit['id']] = $unit;
            }
        }
        foreach ($fresh as $unit) {
            if (($unit['status'] ?? null) === 'skipped') {
                continue;
            }
            $byId[$unit['id']] = $unit;
        }

        return array_values($byId);
    }

    /** @param  list<array<string, mixed>>  $units */
    private function allReady(array $units): bool
    {
        foreach ($units as $unit) {
            if (($unit['status'] ?? null) === 'failed') {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function sourceToArray(GraphSource $source): array
    {
        return [
            'namespace' => $source->namespace,
            'version' => $source->version,
            'type' => $source->type,
            'key' => $source->key,
            'title' => $source->title,
            'location' => $source->location,
            'revision' => $source->revision,
            'digest' => $source->digest,
            'metadata' => $source->metadata,
        ];
    }

    /** @return array<string, mixed> */
    private function nodeToArray(GraphNode $node): array
    {
        return [
            'namespace' => $node->namespace,
            'version' => $node->version,
            'type' => $node->type,
            'key' => $node->key,
            'label' => $node->label,
            'sourceIds' => $node->sourceIds,
            'metadata' => $node->metadata,
        ];
    }

    /** @return array<string, mixed> */
    private function edgeToArray(GraphEdge $edge): array
    {
        return [
            'namespace' => $edge->namespace,
            'version' => $edge->version,
            'relation' => $edge->relation,
            'from' => $edge->from,
            'to' => $edge->to,
            'sourceIds' => $edge->sourceIds,
            'metadata' => $edge->metadata,
        ];
    }
}
