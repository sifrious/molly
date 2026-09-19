<?php

namespace Sifrious\Molly\Knowledge;

use Illuminate\Support\Facades\File;

/**
 * Exact-version graph cache under ~/.molly/graph-cache.
 *
 * Entries are keyed by namespace + package + exact version. A different version never hits.
 */
final class GraphCache
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private ?string $root = null) {}

    public function root(): string
    {
        if ($this->root !== null) {
            return $this->root;
        }

        $env = getenv('MOLLY_HOME');
        $home = (is_string($env) && $env !== '')
            ? rtrim(str_replace('\\', '/', $env), '/')
            : rtrim(str_replace('\\', '/', getenv('HOME') ?: sys_get_temp_dir()), '/').'/.molly';

        return $home.'/graph-cache';
    }

    public function path(string $namespace, string $package, string $exactVersion): string
    {
        $safePackage = str_replace('/', '__', $package);

        return $this->root().'/'.$namespace.'/'.$safePackage.'/'.$exactVersion.'/snapshot.json';
    }

    /**
     * @return array{schema_version: int, namespace: string, package: string, exact_version: string, graph_version_key: string, sources: list<array<string, mixed>>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>, built_at: string}|null
     */
    public function get(string $namespace, string $package, string $exactVersion): ?array
    {
        $path = $this->path($namespace, $package, $exactVersion);
        if (! is_file($path)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return null;
        }
        if (($payload['namespace'] ?? null) !== $namespace
            || ($payload['package'] ?? null) !== $package
            || ($payload['exact_version'] ?? null) !== $exactVersion) {
            return null;
        }

        return $payload;
    }

    /**
     * @param  array{graph_version_key?: string, sources: list<array<string, mixed>>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $snapshot
     */
    public function put(string $namespace, string $package, string $exactVersion, array $snapshot): void
    {
        $path = $this->path($namespace, $package, $exactVersion);
        File::ensureDirectoryExists(dirname($path), 0700);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'namespace' => $namespace,
            'package' => $package,
            'exact_version' => $exactVersion,
            'graph_version_key' => $snapshot['graph_version_key'] ?? $exactVersion,
            'built_at' => gmdate('c'),
            'sources' => $snapshot['sources'],
            'nodes' => $snapshot['nodes'],
            'edges' => $snapshot['edges'],
        ];
        $mask = umask(0077);
        try {
            File::put($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        } finally {
            umask($mask);
        }
    }
}
