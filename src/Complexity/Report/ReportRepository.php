<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Report;

use JsonException;
use Sifrious\Molly\Complexity\Support\CleverConfig;
use Throwable;

/**
 * The read side of the report file. Never throws: a missing file reads as
 * null, and an unusable file (bad JSON, wrong schema) reads as null with
 * wasCorrupt() flagged so callers can warn.
 */
final class ReportRepository
{
    public const int SCHEMA_VERSION = 1;

    private bool $wasCorrupt = false;

    public function __construct(private readonly CleverConfig $config) {}

    public function path(): string
    {
        return $this->config->reportPath();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        $this->wasCorrupt = false;
        $path = $this->path();

        if (! is_file($path)) {
            return null;
        }

        try {
            $contents = file_get_contents($path);
        } catch (Throwable) {
            $contents = false;
        }

        if ($contents === false) {
            $this->wasCorrupt = true;

            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->wasCorrupt = true;

            return null;
        }

        if (! is_array($decoded) || ($decoded['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $this->wasCorrupt = true;

            return null;
        }

        $document = [];

        foreach ($decoded as $key => $value) {
            $document[(string) $key] = $value;
        }

        return $document;
    }

    /**
     * True only when the last read() found a file it could not use.
     * missing is not corrupt.
     */
    public function wasCorrupt(): bool
    {
        return $this->wasCorrupt;
    }
}
