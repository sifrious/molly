<?php

namespace Sifrious\Molly\ModelFit;

use RuntimeException;
use Sifrious\Molly\Settings\SettingsStore;

/**
 * Machine-level model records under MOLLY_HOME/models: the readiness results that make
 * a model already_installed, the install journal that lets an interrupted download
 * resume truthfully, and the lock that keeps two installs from running at once. Each
 * JSON file is written to a temporary file and renamed into place.
 */
class ModelRecords
{
    public const READINESS_SCHEMA = 'molly.model-readiness/1';

    public function __construct(private SettingsStore $settings) {}

    public function directory(): string
    {
        return $this->settings->home().'/models';
    }

    /** @return array<string, array<string, mixed>> Readiness records keyed by model name. */
    public function readiness(): array
    {
        $document = $this->read('readiness.json');

        return ($document['schema'] ?? null) === self::READINESS_SCHEMA && is_array($document['records'] ?? null) ? $document['records'] : [];
    }

    /** The digest of the readiness records a decision used, or null when there are none. */
    public function readinessDigest(): ?string
    {
        $records = $this->readiness();

        return $records === [] ? null : hash('sha256', json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param  array<string, mixed>  $record */
    public function recordReadiness(string $model, array $record): void
    {
        $records = $this->readiness();
        $records[$model] = $record;
        ksort($records);
        $this->write('readiness.json', ['schema' => self::READINESS_SCHEMA, 'records' => $records]);
    }

    /** @return array<string, mixed>|null The last install journal entry for this model. */
    public function journal(string $model): ?array
    {
        $journal = $this->read('install-journal.json');

        return is_array($journal[$model] ?? null) ? $journal[$model] : null;
    }

    /** @param  array<string, mixed>  $entry */
    public function recordJournal(string $model, array $entry): void
    {
        $journal = $this->read('install-journal.json') ?? [];
        $journal[$model] = $entry;
        ksort($journal);
        $this->write('install-journal.json', $journal);
    }

    /**
     * Take the install lock without waiting. Returns the open handle, or null when
     * another install holds it. Closing the handle releases the lock.
     *
     * @return resource|null
     */
    public function lock()
    {
        $this->ensureDirectory();
        $handle = @fopen($this->directory().'/install.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('MODEL_RECORDS_UNWRITABLE: Molly could not open '.$this->directory().'/install.lock. Check that MOLLY_HOME is writable.');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @return array<string, mixed>|null */
    private function read(string $file): ?array
    {
        $path = $this->directory().'/'.$file;
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param  array<string, mixed>  $document */
    private function write(string $file, array $document): void
    {
        $this->ensureDirectory();
        $path = $this->directory().'/'.$file;
        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION)."\n";
        if (@file_put_contents($temporary, $json) !== strlen($json) || ! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException('MODEL_RECORDS_UNWRITABLE: Molly could not write '.$path.'. Check free disk space and that MOLLY_HOME is writable.');
        }
    }

    private function ensureDirectory(): void
    {
        $directory = $this->directory();
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('MODEL_RECORDS_UNWRITABLE: Molly could not create '.$directory.'. Check that MOLLY_HOME is writable.');
        }
    }
}
