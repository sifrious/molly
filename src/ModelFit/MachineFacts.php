<?php

namespace Sifrious\Molly\ModelFit;

use InvalidArgumentException;
use Sifrious\Molly\Hardware\HardwareSnapshot;

/**
 * The facts a fit decision reads, taken from one molly.hardware-snapshot/1 document.
 * A null value is a fact molly:preflight could not measure; the decision reports it
 * as unknown and never guesses it.
 */
final readonly class MachineFacts
{
    /**
     * @param  list<array{name: string, digest: string, size_bytes: int|null}>|null  $installedModels
     * @param  list<array{name: string, digest: string, size_bytes: int|null}>|null  $loadedModels  the models Ollama holds in memory
     */
    public function __construct(
        public string $snapshotSha256,
        public string $measuredAt,
        public ?string $os,
        public ?bool $macos,
        public ?string $osVersion,
        public ?string $architecture,
        public ?bool $arm64Capable,
        public ?bool $translated,
        public ?string $cpuBrand,
        public ?int $totalMemoryBytes,
        public ?string $memoryPressure,
        public ?bool $metalAvailable,
        public ?string $runtimeCliVersion,
        public ?string $runtimeApiVersion,
        public ?array $installedModels,
        public ?string $destination,
        public ?bool $volumeMissing,
        public ?bool $destinationWritable,
        public ?int $freeDiskBytes,
        public ?int $totalDiskBytes,
        public ?string $volumeName,
        public ?string $mountPoint,
        public ?int $availableMemoryBytes = null,
        public ?array $loadedModels = null,
    ) {
        if ($totalMemoryBytes !== null && $totalMemoryBytes < 1) {
            throw new InvalidArgumentException('Total memory must be positive when it is known.');
        }
        if (($freeDiskBytes !== null && $freeDiskBytes < 0) || ($totalDiskBytes !== null && $totalDiskBytes < 0)) {
            throw new InvalidArgumentException('Disk space cannot be negative.');
        }
    }

    /**
     * Read the facts from a snapshot document. The snapshot must carry the digest of its
     * own facts, so a decision always names the exact facts it used.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function fromSnapshot(array $snapshot): self
    {
        $facts = $snapshot['facts'] ?? null;
        if (($snapshot['schema'] ?? null) !== HardwareSnapshot::SCHEMA || ! is_array($facts)) {
            throw new InvalidArgumentException('SNAPSHOT_INVALID: The document is not a '.HardwareSnapshot::SCHEMA.' snapshot.');
        }
        if (! is_string($snapshot['snapshot_sha256'] ?? null) || ! hash_equals(HardwareSnapshot::hash($facts), $snapshot['snapshot_sha256'])) {
            throw new InvalidArgumentException('SNAPSHOT_INVALID: snapshot_sha256 does not match the facts in the snapshot.');
        }

        $value = static function (string $group, string $name, string $type) use ($facts): mixed {
            $fact = $facts[$group][$name] ?? null;
            if (! is_array($fact) || ($fact['status'] ?? null) !== 'measured') {
                return null;
            }

            return match ($type) {
                'bool' => is_bool($fact['value']) ? $fact['value'] : null,
                'int' => is_int($fact['value']) ? $fact['value'] : null,
                'string' => is_string($fact['value']) && $fact['value'] !== '' ? $fact['value'] : null,
                'list' => is_array($fact['value']) ? $fact['value'] : null,
            };
        };

        $models = static fn (?array $models): ?array => $models === null ? null : array_values(array_filter(array_map(
            fn (mixed $model): ?array => is_array($model) && is_string($model['name'] ?? null) && is_string($model['digest'] ?? null)
                ? ['name' => $model['name'], 'digest' => strtolower($model['digest']), 'size_bytes' => is_int($model['size_bytes'] ?? null) ? $model['size_bytes'] : null]
                : null,
            $models,
        )));

        return new self(
            snapshotSha256: $snapshot['snapshot_sha256'],
            measuredAt: is_string($snapshot['measured_at'] ?? null) ? $snapshot['measured_at'] : '',
            os: $value('platform', 'os', 'string'),
            macos: $value('platform', 'macos', 'bool'),
            osVersion: $value('platform', 'os_version', 'string'),
            architecture: $value('platform', 'architecture', 'string'),
            arm64Capable: $value('platform', 'arm64_capable', 'bool'),
            translated: $value('platform', 'translated', 'bool'),
            cpuBrand: $value('platform', 'cpu_brand', 'string'),
            totalMemoryBytes: $value('memory', 'total_bytes', 'int'),
            memoryPressure: $value('memory', 'pressure_level', 'string'),
            metalAvailable: $value('acceleration', 'metal_available', 'bool'),
            runtimeCliVersion: $value('ollama', 'cli_version', 'string'),
            runtimeApiVersion: $value('ollama', 'api_version', 'string'),
            installedModels: $models($value('ollama', 'installed_models', 'list')),
            destination: $value('disk', 'destination', 'string'),
            volumeMissing: $value('disk', 'volume_missing', 'bool'),
            destinationWritable: $value('disk', 'writable', 'bool'),
            freeDiskBytes: $value('disk', 'free_bytes', 'int'),
            totalDiskBytes: $value('disk', 'total_bytes', 'int'),
            volumeName: $value('disk', 'volume_name', 'string'),
            mountPoint: $value('disk', 'mount_point', 'string'),
            availableMemoryBytes: $value('memory', 'available_bytes', 'int'),
            loadedModels: $models($value('ollama', 'loaded_models', 'list')),
        );
    }

    /**
     * Whether the hardware is Apple silicon. A translated process reports x86_64 from
     * uname -m, so the arm64 capability and the CPU brand come first.
     */
    public function appleSilicon(): ?bool
    {
        return match (true) {
            $this->arm64Capable !== null => $this->arm64Capable,
            $this->cpuBrand !== null && str_starts_with($this->cpuBrand, 'Apple') => true,
            $this->cpuBrand !== null && str_contains($this->cpuBrand, 'Intel') => false,
            $this->architecture === 'arm64' => true,
            $this->architecture === 'x86_64' && $this->translated === false => false,
            default => null,
        };
    }

    /**
     * The bytes Ollama holds in memory for this exact model, matched by name and digest,
     * or null when it is not loaded, its size is unknown, or Molly could not read the list.
     */
    public function loadedBytes(string $name, string $digest): ?int
    {
        foreach ($this->loadedModels ?? [] as $model) {
            if ($model['name'] === $name && hash_equals(strtolower($digest), $model['digest'])) {
                return $model['size_bytes'];
            }
        }

        return null;
    }

    /**
     * The installed model with this name: its entry, false when the measured list lacks
     * it, or null when Molly could not read the list.
     *
     * @return array{name: string, digest: string, size_bytes: int|null}|false|null
     */
    public function installed(string $name): array|false|null
    {
        if ($this->installedModels === null) {
            return null;
        }
        foreach ($this->installedModels as $model) {
            if ($model['name'] === $name) {
                return $model;
            }
        }

        return false;
    }
}
