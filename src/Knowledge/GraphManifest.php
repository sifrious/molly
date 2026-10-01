<?php

namespace Sifrious\Molly\Knowledge;

use RuntimeException;
use Sifrious\Molly\Workspace\Directory;

/** Load and validate graph bootstrap manifests under `.molly/graphs/manifest.json`. */
final class GraphManifest
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  list<array<string, mixed>>  $units
     * @param  array<string, mixed>  $packages
     */
    public function __construct(
        public readonly int $schemaVersion,
        public readonly ?string $projectPath,
        public readonly ?string $lockPath,
        public readonly ?string $lockHash,
        public readonly ?string $laravelExact,
        public readonly ?int $laravelMajor,
        public readonly array $packages,
        public readonly array $units,
        public readonly ?string $updatedAt,
        public readonly ?string $path,
        public readonly ?string $revision = null,
    ) {}

    public static function empty(?string $path = null): self
    {
        return new self(
            schemaVersion: self::SCHEMA_VERSION,
            projectPath: null,
            lockPath: null,
            lockHash: null,
            laravelExact: null,
            laravelMajor: null,
            packages: [],
            units: [],
            updatedAt: null,
            path: $path,
        );
    }

    public static function pathFor(string $root): string
    {
        return rtrim($root, '/').'/.molly/graphs/manifest.json';
    }

    public static function load(string $rootOrPath): self
    {
        $path = str_ends_with($rootOrPath, 'manifest.json')
            ? $rootOrPath
            : self::pathFor($rootOrPath);

        if (! is_file($path)) {
            return self::empty($path);
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('KNOWLEDGE_MANIFEST_INVALID: Molly could not read the graph manifest.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('KNOWLEDGE_MANIFEST_INVALID: The graph manifest is not valid JSON.', previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('KNOWLEDGE_MANIFEST_INVALID: The graph manifest must be a JSON object.');
        }

        return self::fromArray($decoded, $path);
    }

    /** @param  array<string, mixed>  $payload */
    public static function fromArray(array $payload, ?string $path = null): self
    {
        if (! array_key_exists('schema_version', $payload)) {
            throw new RuntimeException('KNOWLEDGE_MANIFEST_INVALID: The graph manifest is missing schema_version.');
        }

        $version = $payload['schema_version'];
        if (! is_int($version) && ! (is_string($version) && ctype_digit($version))) {
            throw new RuntimeException('KNOWLEDGE_MANIFEST_INVALID: schema_version must be an integer.');
        }
        $version = (int) $version;
        if ($version !== self::SCHEMA_VERSION) {
            throw new RuntimeException('KNOWLEDGE_MANIFEST_INCOMPATIBLE: Graph manifest schema '.$version.' is not supported (expected '.self::SCHEMA_VERSION.').');
        }

        $units = [];
        if (array_key_exists('units', $payload)) {
            if (! is_array($payload['units'])) {
                throw new RuntimeException('KNOWLEDGE_MANIFEST_INVALID: units must be a list.');
            }
            foreach ($payload['units'] as $unit) {
                if (! is_array($unit)) {
                    throw new RuntimeException('KNOWLEDGE_MANIFEST_INVALID: Each unit must be an object.');
                }
                $units[] = $unit;
            }
        }

        $packages = [];
        if (isset($payload['packages'])) {
            if (! is_array($payload['packages'])) {
                throw new RuntimeException('KNOWLEDGE_MANIFEST_INVALID: packages must be an object.');
            }
            $packages = $payload['packages'];
        }

        $major = $payload['laravel_major'] ?? null;
        if ($major !== null && ! is_int($major) && ! (is_string($major) && ctype_digit((string) $major))) {
            throw new RuntimeException('KNOWLEDGE_MANIFEST_INVALID: laravel_major must be an integer when present.');
        }

        return new self(
            schemaVersion: $version,
            projectPath: self::stringOrNull($payload['project_path'] ?? null),
            lockPath: self::stringOrNull($payload['lock_path'] ?? null),
            lockHash: self::stringOrNull($payload['lock_hash'] ?? null),
            laravelExact: self::stringOrNull($payload['laravel_exact'] ?? null),
            laravelMajor: $major === null ? null : (int) $major,
            packages: $packages,
            units: array_values($units),
            updatedAt: self::stringOrNull($payload['updated_at'] ?? null),
            path: $path,
            revision: self::stringOrNull($payload['revision'] ?? null),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Replace or insert one unit by id; drop skipped placeholders; preserve order of untouched units.
     *
     * @param  array<string, mixed>  $unit
     */
    public function withReplacedUnit(array $unit): self
    {
        if (! isset($unit['id']) || ! is_string($unit['id']) || $unit['id'] === '') {
            throw new RuntimeException('KNOWLEDGE_MANIFEST_INVALID: A unit replacement requires a string id.');
        }
        if (($unit['status'] ?? null) === 'skipped') {
            throw new RuntimeException('KNOWLEDGE_MANIFEST_INVALID: Skipped placeholders cannot replace a unit.');
        }

        $byId = [];
        $order = [];
        foreach ($this->units as $existing) {
            if (! is_array($existing) || ! isset($existing['id']) || ! is_string($existing['id'])) {
                continue;
            }
            if (($existing['status'] ?? null) === 'skipped') {
                continue;
            }
            $byId[$existing['id']] = $existing;
            $order[] = $existing['id'];
        }

        if (! isset($byId[$unit['id']])) {
            $order[] = $unit['id'];
        }
        $byId[$unit['id']] = $unit;

        $units = [];
        foreach ($order as $id) {
            $units[] = $byId[$id];
        }

        return new self(
            schemaVersion: $this->schemaVersion,
            projectPath: $this->projectPath,
            lockPath: $this->lockPath,
            lockHash: $this->lockHash,
            laravelExact: $this->laravelExact,
            laravelMajor: $this->laravelMajor,
            packages: $this->packages,
            units: $units,
            updatedAt: $this->updatedAt,
            path: $this->path,
            revision: $this->revision,
        );
    }

    public function allReady(): bool
    {
        if ($this->units === []) {
            return false;
        }
        foreach ($this->units as $unit) {
            if (! is_array($unit) || ($unit['status'] ?? null) !== 'ready') {
                return false;
            }
        }

        return true;
    }

    /**
     * Compare what each ready unit recorded when it was built with the checkout now.
     *
     * Reasons: `revision_changed` (Git HEAD moved), `revision_unrecorded` (built before
     * Molly recorded revisions), `lock_changed` (composer.lock bytes differ), and
     * `version_changed` (the unit's package is no longer at the recorded exact version).
     *
     * @param  array<string, string>  $packages  current composer.lock versions by package name
     * @param  list<string>|null  $unitIds  only these units; null checks every unit
     * @return array{status: string, stale: ?bool, reasons: list<string>, units: list<array<string, mixed>>, current: array{revision: string, lock_hash: ?string}, fix: ?string}
     */
    public function freshness(string $revision, ?string $lockHash, array $packages, ?array $unitIds = null): array
    {
        $checked = [];
        $reasons = [];
        foreach ($this->units as $unit) {
            $id = is_string($unit['id'] ?? null) ? $unit['id'] : null;
            if ($id === null || ($unitIds !== null && ! in_array($id, $unitIds, true)) || ($unit['status'] ?? null) !== 'ready') {
                continue;
            }

            $recordedRevision = is_string($unit['revision'] ?? null) ? $unit['revision'] : null;
            $recordedLock = is_string($unit['lock_hash'] ?? null) ? $unit['lock_hash'] : $this->lockHash;
            $package = is_string($unit['package'] ?? null) ? $unit['package'] : null;
            $unitReasons = [];
            if ($recordedRevision === null) {
                $unitReasons[] = 'revision_unrecorded';
            } elseif ($recordedRevision !== $revision) {
                $unitReasons[] = 'revision_changed';
            }
            if ($recordedLock !== $lockHash) {
                $unitReasons[] = 'lock_changed';
            }
            if ($package !== null && $package !== 'project' && ($packages[$package] ?? null) !== ($unit['exact_version'] ?? null)) {
                $unitReasons[] = 'version_changed';
            }

            $checked[] = [
                'id' => $id,
                'stale' => $unitReasons !== [],
                'reasons' => $unitReasons,
                'recorded' => [
                    'revision' => $recordedRevision,
                    'lock_hash' => $recordedLock,
                    'exact_version' => $unit['exact_version'] ?? null,
                ],
                'current_version' => $package === null || $package === 'project' ? null : ($packages[$package] ?? null),
            ];
            array_push($reasons, ...$unitReasons);
        }

        $reasons = array_values(array_unique($reasons));
        sort($reasons);
        $status = $checked === [] ? 'missing' : ($reasons === [] ? 'fresh' : 'stale');

        return [
            'status' => $status,
            'stale' => $status === 'missing' ? null : $reasons !== [],
            'reasons' => $reasons,
            'units' => $checked,
            'current' => ['revision' => $revision, 'lock_hash' => $lockHash],
            'fix' => $status === 'fresh' ? null : 'php artisan molly:graphs-bootstrap',
        ];
    }

    /**
     * Write this manifest privately and atomically under the project graphs directory.
     * Never leaves a partial file that could make a failed unit look ready.
     */
    public function write(string $root): string
    {
        $directory = Directory::molly($root, 'graphs/manifest.json');
        $directory = dirname($directory);
        clearstatcache(true, $directory);
        if (is_link($directory) || (file_exists($directory) && ! is_dir($directory))) {
            throw new RuntimeException('KNOWLEDGE_MANIFEST_INVALID: Graph manifest directories must be real directories, not links or files.');
        }
        Directory::ensure($directory, 0700);
        @chmod($directory, 0700);

        $path = $directory.'/manifest.json';
        $payload = $this->toArray();
        // Deterministic formatting for reproducible digests.
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        Directory::replaceFile($path, $json, 'KNOWLEDGE_MANIFEST_UNWRITABLE');

        return $path;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'project_path' => $this->projectPath,
            'lock_path' => $this->lockPath,
            'lock_hash' => $this->lockHash,
            'laravel_exact' => $this->laravelExact,
            'laravel_major' => $this->laravelMajor,
            'revision' => $this->revision,
            'packages' => $this->packages,
            'units' => $this->units,
            'updated_at' => $this->updatedAt,
        ];
    }
}
