<?php

namespace Sifrious\Molly\Knowledge;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Exact-version graph cache under ~/.molly/graph-cache.
 *
 * Entries are keyed by namespace + package + exact version. A different version never hits.
 * Cached graph records are hydrated through GraphSnapshot; malformed payloads miss.
 * Writes stage JSON in the same directory, verify complete bytes, set private perms, then rename.
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

        if (! $this->hydrateSnapshot($namespace, $payload)) {
            return null;
        }

        return $payload;
    }

    /**
     * @param  array{graph_version_key?: string, sources: list<array<string, mixed>>, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $snapshot
     */
    public function put(string $namespace, string $package, string $exactVersion, array $snapshot): void
    {
        $graphVersionKey = $snapshot['graph_version_key'] ?? $exactVersion;
        $candidate = [
            'schema_version' => self::SCHEMA_VERSION,
            'namespace' => $namespace,
            'package' => $package,
            'exact_version' => $exactVersion,
            'graph_version_key' => $graphVersionKey,
            'built_at' => gmdate('c'),
            'sources' => $snapshot['sources'],
            'nodes' => $snapshot['nodes'],
            'edges' => $snapshot['edges'],
        ];

        if (! $this->hydrateSnapshot($namespace, $candidate)) {
            throw new RuntimeException('KNOWLEDGE_SNAPSHOT_INVALID: Cache put rejected a malformed graph snapshot.');
        }

        $path = $this->path($namespace, $package, $exactVersion);
        $directory = dirname($path);
        File::ensureDirectoryExists($directory, 0700);
        @chmod($directory, 0700);

        $json = json_encode($candidate, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $temporary = $directory.'/.graph-cache-'.bin2hex(random_bytes(8)).'.tmp';
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('KNOWLEDGE_CACHE_WRITE_FAILED: Molly could not stage the graph cache entry.');
        }

        try {
            $written = @fwrite($handle, $json);
            @fclose($handle);
            $handle = null;
            if ($written !== strlen($json)) {
                throw new RuntimeException('KNOWLEDGE_CACHE_WRITE_FAILED: The graph cache entry could not be written in full.');
            }
            @chmod($temporary, 0600);
            if (! @rename($temporary, $path)) {
                throw new RuntimeException('KNOWLEDGE_CACHE_WRITE_FAILED: The graph cache entry could not be replaced atomically.');
            }
            @chmod($path, 0600);
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            if (is_file($temporary) && ! is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hydrateSnapshot(string $namespace, array $payload): bool
    {
        $version = $payload['graph_version_key'] ?? $payload['exact_version'] ?? null;
        if (! is_string($version) || $version === '') {
            return false;
        }

        try {
            GraphSnapshot::fromArray([
                'namespace' => $namespace,
                'version' => $version,
                'sources' => array_values(array_map(
                    fn (mixed $row): array => is_array($row) ? $row : [],
                    is_array($payload['sources'] ?? null) ? $payload['sources'] : [],
                )),
                'nodes' => array_values(array_map(
                    fn (mixed $row): array => is_array($row) ? $this->normalizeSourceIds($row) : [],
                    is_array($payload['nodes'] ?? null) ? $payload['nodes'] : [],
                )),
                'edges' => array_values(array_map(
                    fn (mixed $row): array => is_array($row) ? $this->normalizeSourceIds($row) : [],
                    is_array($payload['edges'] ?? null) ? $payload['edges'] : [],
                )),
            ]);
        } catch (RuntimeException) {
            return false;
        }

        return true;
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
}
