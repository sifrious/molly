<?php

namespace Sifrious\Molly\Acceptance;

use RuntimeException;

final class EvidenceRecorder
{
    /** @return array<string, mixed>|null */
    public function latest(string $root): ?array
    {
        $pointer = $this->read($root.'/latest.json');
        if ($pointer === null || ! is_string($pointer['run_id'] ?? null)) {
            return null;
        }

        return $this->run($root, $pointer['run_id']);
    }

    /** @return array<string, mixed>|null */
    public function run(string $root, string $runId): ?array
    {
        return $this->read($root.'/runs/'.$runId.'/result.json');
    }

    /** @return array<string, mixed>|null */
    public function attempt(string $root, string $runId, string $checkId, ?int $attempt = null): ?array
    {
        $directory = $root.'/runs/'.$runId.'/checks/'.$checkId;
        if ($attempt === null) {
            $attempt = $this->nextAttempt($root, $runId, $checkId) - 1;
        }
        if ($attempt < 1) {
            return null;
        }

        return $this->read($directory.'/attempt-'.$attempt.'.json');
    }

    public function nextAttempt(string $root, string $runId, string $checkId): int
    {
        $directory = $root.'/runs/'.$runId.'/checks/'.$checkId;
        if (! is_dir($directory)) {
            return 1;
        }
        $found = 0;
        foreach (scandir($directory) ?: [] as $name) {
            if (preg_match('/\Aattempt-(\d+)\.json\z/', $name, $match) === 1) {
                $found = max($found, (int) $match[1]);
            }
        }

        return $found + 1;
    }

    public function writeAttempt(string $root, VerificationCheckResult $result): string
    {
        $relative = 'runs/'.$result->runId.'/checks/'.$result->checkId.'/attempt-'.$result->attempt.'.json';
        $this->write($root.'/'.$relative, $result->toArray());

        return $relative;
    }

    /** @param  array<string, mixed>  $document */
    public function writeRun(string $root, string $runId, array $document): void
    {
        $this->write($root.'/runs/'.$runId.'/result.json', $document);
        $this->write($root.'/latest.json', [
            'schema' => 'molly.acceptance-verification-pointer/1',
            'run_id' => $runId,
            'candidate_sha' => $document['candidate_sha'] ?? null,
            'updated_at' => $document['finished_at'] ?? null,
        ]);
    }

    /** @param  array<string, mixed>  $preflight */
    public function writePreflight(string $root, string $runId, array $preflight, int $attempt): void
    {
        $this->write($root.'/runs/'.$runId.'/preflight-attempt-'.$attempt.'.json', $preflight);
        $this->write($root.'/runs/'.$runId.'/preflight.json', $preflight);
    }

    public function preflightAttempt(string $root, string $runId): int
    {
        $directory = $root.'/runs/'.$runId;
        if (! is_dir($directory)) {
            return 1;
        }
        $found = 0;
        foreach (scandir($directory) ?: [] as $name) {
            if (preg_match('/\Apreflight-attempt-(\d+)\.json\z/', $name, $match) === 1) {
                $found = max($found, (int) $match[1]);
            }
        }

        return $found + 1;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{previous: string, changed: bool}
     */
    public function appendPermissionHistory(string $root, array $entry): array
    {
        $path = $root.'/permission-history.json';
        $history = $this->read($path) ?? ['schema' => 'molly.verifier-permission-history/1', 'entries' => []];
        $entries = is_array($history['entries'] ?? null) ? $history['entries'] : [];
        $previous = $entries === [] ? null : $entries[array_key_last($entries)];
        $previousDisposition = is_array($previous) ? (string) ($previous['disposition'] ?? PermissionHistoryDisposition::NeverChecked->value) : PermissionHistoryDisposition::NeverChecked->value;
        $changed = is_array($previous) && (
            ($previous['screen_recording'] ?? null) !== ($entry['screen_recording'] ?? null)
            || ($previous['accessibility'] ?? null) !== ($entry['accessibility'] ?? null)
        );
        $entry['previous_disposition'] = $previousDisposition;
        $entry['changed_since_previous'] = $changed;
        $entries[] = $entry;
        $this->write($path, ['schema' => 'molly.verifier-permission-history/1', 'entries' => $entries]);

        return ['previous' => $previousDisposition, 'changed' => $changed];
    }

    /** @param  array<string, mixed>  $document */
    private function write(string $path, array $document): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('EVIDENCE_UNWRITABLE: Cannot create '.$directory.'.');
        }
        chmod($directory, 0700);
        $mask = umask(0077);
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        try {
            $encoded = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            if (file_put_contents($temporary, $encoded, LOCK_EX) === false || ! rename($temporary, $path)) {
                throw new RuntimeException('EVIDENCE_UNWRITABLE: Cannot save '.$path.'.');
            }
            chmod($path, 0600);
        } finally {
            umask($mask);
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** @return array<string, mixed>|null */
    private function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
