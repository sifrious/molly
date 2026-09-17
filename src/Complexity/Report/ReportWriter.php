<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Report;

use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use OutOfBoundsException;
use RuntimeException;
use Sifrious\Molly\Complexity\Probes\ProbeResult;
use Sifrious\Molly\Complexity\Support\CleverConfig;
use Sifrious\Molly\Complexity\Support\Git;

/**
 * The write side of the report: one flat pretty-printed JSON document,
 * overwritten atomically (temp file, then rename) every run. the current
 * numbers replace the last ones, and nothing keeps history.
 */
final class ReportWriter
{
    private bool $startedFresh = false;

    public function __construct(
        private readonly ReportRepository $repository,
        private readonly CleverConfig $config,
        private readonly Git $git,
        private readonly Application $app,
    ) {}

    /**
     * Full overwrite (clever:scan): unknown probe keys from previous runs
     * are dropped. Returns the document written.
     *
     * @param  list<ProbeResult>  $results
     * @return array<string, mixed>
     */
    public function writeAll(array $results): array
    {
        $probes = [];

        foreach ($results as $result) {
            $probes[$result->key] = $result->toArray();
        }

        return $this->write($probes);
    }

    /**
     * Read-modify-write (single-probe commands): replaces only this
     * probe's section. A missing report starts fresh silently; a corrupt
     * one starts fresh with startedFresh() flagged so the command warns.
     *
     * @return array<string, mixed>
     */
    public function mergeOne(ProbeResult $result): array
    {
        $existing = $this->repository->read();
        $this->startedFresh = $existing === null && $this->repository->wasCorrupt();

        $probes = [];

        if ($existing !== null && is_array($existing['probes'] ?? null)) {
            foreach ($existing['probes'] as $key => $entry) {
                if (is_string($key) && is_array($entry)) {
                    $probes[$key] = $entry;
                }
            }
        }

        $probes[$result->key] = $result->toArray();

        return $this->write($probes);
    }

    public function startedFresh(): bool
    {
        return $this->startedFresh;
    }

    /**
     * @param  array<string, array<string, mixed>>  $probes
     * @return array<string, mixed>
     */
    private function write(array $probes): array
    {
        uksort($probes, strnatcmp(...));

        $document = [
            'schema_version' => ReportRepository::SCHEMA_VERSION,
            'generated_at' => Date::now()->toIso8601String(),
            'app_name' => $this->config->appName(),
            'environment' => $this->app->environment(),
            'git' => $this->git->context()->toArray(),
            'package_version' => $this->packageVersion(),
            'probes' => $probes,
        ];

        $this->atomicWrite($document);

        return $document;
    }

    private function packageVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('sifrious/molly') ?? 'unknown';
        } catch (OutOfBoundsException) {
            return 'unknown';
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function atomicWrite(array $document): void
    {
        $path = $this->repository->path();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create the report directory [%s].', $directory));
        }

        $json = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";

        $temp = $path.'.tmp';

        if (file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Could not write the report temp file [%s].', $temp));
        }

        if (! rename($temp, $path)) {
            unlink($temp);

            throw new RuntimeException(sprintf('Could not move the report into place at [%s].', $path));
        }
    }
}
