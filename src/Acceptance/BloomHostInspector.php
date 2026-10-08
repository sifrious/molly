<?php

namespace Sifrious\Molly\Acceptance;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Reads a Bloom process that is already running, and whether MollySurfaces is loaded in it.
 * It does not launch Bloom, request a macOS permission, or open Settings.
 * When more than one Bloom app is running, BLOOM_APP (or the constructor path) selects it.
 * The first matching ps line is not that selection.
 */
final class BloomHostInspector
{
    public int $inspections = 0;

    /**
     * @param  string|null  $processListing  Output of `ps -ax -o pid=,command=`. Null reads the live process list.
     * @param  string|null  $pluginsDirectory  Bloom's Plugins directory. Null uses BLOOM_PLUGINS_DIR or ~/Library/Application Support/Bloom/Plugins.
     * @param  string|null  $intendedHost  App path or bundle id. Null reads BLOOM_APP.
     * @param  string|null  $loadEvidence  lsof output or a registration log. Null runs lsof for the selected pid. An empty string means the caller looked and found nothing.
     */
    public function __construct(
        private ?string $processListing = null,
        private ?string $pluginsDirectory = null,
        private ?string $intendedHost = null,
        private ?string $loadEvidence = null,
    ) {}

    public function inspect(): BloomHostSnapshot
    {
        $this->inspections++;
        $listing = $this->processListing ?? $this->readProcesses();
        [$pid, $command, $ambiguous] = $this->bloomProcess($listing);
        $plugins = $this->pluginsDirectory ?? $this->defaultPluginsDirectory();
        $pluginJson = rtrim($plugins, '/').'/sifrious.molly/plugin.json';
        $enabledPath = rtrim($plugins, '/').'/enabled.json';
        [$pluginId, $apiVersion] = $this->manifest($pluginJson);
        $enabled = $pluginId !== null && $this->enabled($enabledPath, $pluginId);
        [$loaded, $loadSource] = $pid === null ? [false, ''] : $this->pluginLoaded($pid);

        return new BloomHostSnapshot(
            $pid !== null,
            $pid,
            $command,
            $pluginId,
            $apiVersion,
            $enabled,
            $this->processListing === null ? 'ps -ax -o pid=,command=' : 'supplied-process-listing',
            $pluginJson,
            $loaded,
            $loadSource,
            $ambiguous,
        );
    }

    private function readProcesses(): string
    {
        try {
            $result = Process::timeout(5)->run(['ps', '-ax', '-o', 'pid=,command=']);
        } catch (Throwable) {
            return '';
        }

        return $result->successful() ? $result->output() : '';
    }

    /** @return array{0: int|null, 1: string, 2: bool} */
    private function bloomProcess(string $listing): array
    {
        $found = [];
        foreach (preg_split("/\r\n|\n|\r/", $listing) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/\A(\d+)\s+(.*)\z/', $line, $match) !== 1) {
                continue;
            }
            if (preg_match('#(?:\.app/Contents/MacOS/Bloom(?: Dev)?(?:\s|$)|/MacOS/Bloom(?: Dev)?(?:\s|$))#', $match[2]) !== 1) {
                continue;
            }
            $pid = (int) $match[1];
            if ($pid > 1) {
                $found[] = [$pid, $match[2]];
            }
        }
        $intended = $this->intendedHost;
        if ($intended === null) {
            $env = getenv('BLOOM_APP');
            $intended = is_string($env) && $env !== '' ? $env : null;
        }
        if (is_string($intended) && $intended !== '') {
            $matched = array_values(array_filter($found, fn (array $row): bool => $this->hostMatches($row[1], $intended)));
            if (count($matched) === 1) {
                return [$matched[0][0], $matched[0][1], false];
            }

            return [null, '', $found !== []];
        }
        if (count($found) === 1) {
            return [$found[0][0], $found[0][1], false];
        }

        return [null, '', count($found) > 1];
    }

    private function hostMatches(string $command, string $intended): bool
    {
        $needle = rtrim($intended, '/');
        if ($needle !== '' && str_contains($command, $needle)) {
            return true;
        }
        if (str_contains($intended, '/') || ! str_contains($intended, '.')) {
            return false;
        }
        if (preg_match('#(/.+?\.app)/#', $command, $match) !== 1) {
            return false;
        }
        $plist = $match[1].'/Contents/Info.plist';
        if (! is_file($plist)) {
            return false;
        }

        return str_contains((string) file_get_contents($plist), $intended);
    }

    /** @return array{0: bool, 1: string} */
    private function pluginLoaded(int $pid): array
    {
        if ($this->loadEvidence !== null) {
            return [$this->loadLineMatches($pid, $this->loadEvidence), 'supplied-load-evidence'];
        }
        $source = 'lsof -p '.$pid;
        try {
            $result = Process::timeout(5)->run(['lsof', '-p', (string) $pid]);
        } catch (Throwable) {
            return [false, $source];
        }
        $body = $result->successful() ? $result->output() : '';

        return [$this->loadLineMatches($pid, $body), $source];
    }

    private function loadLineMatches(int $pid, string $evidence): bool
    {
        $pidText = (string) $pid;
        foreach (preg_split("/\r\n|\n|\r/", $evidence) ?: [] as $line) {
            if (str_contains($line, 'MollySurfaces') && str_contains($line, $pidText)) {
                return true;
            }
            if (preg_match('/Registered \d+ Molly surfaces for sifrious\.molly\b/', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: string|null, 1: int|null} */
    private function manifest(string $path): array
    {
        if (! is_file($path)) {
            return [null, null];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! is_string($decoded['id'] ?? null) || $decoded['id'] === '') {
            return [null, null];
        }
        $apiVersion = $decoded['apiVersion'] ?? null;

        return [$decoded['id'], is_int($apiVersion) ? $apiVersion : null];
    }

    private function enabled(string $path, string $pluginId): bool
    {
        if (! is_file($path)) {
            return false;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return false;
        }

        return in_array($pluginId, $decoded, true);
    }

    private function defaultPluginsDirectory(): string
    {
        $override = getenv('BLOOM_PLUGINS_DIR');
        if (is_string($override) && $override !== '') {
            return $override;
        }
        $home = getenv('HOME');

        return (is_string($home) ? $home : '').'/Library/Application Support/Bloom/Plugins';
    }
}
