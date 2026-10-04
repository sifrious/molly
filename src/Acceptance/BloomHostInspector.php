<?php

namespace Sifrious\Molly\Acceptance;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Reads a Bloom process that is already running, and the plugin Bloom loads at launch.
 * It does not launch Bloom, request a macOS permission, or open Settings.
 */
final class BloomHostInspector
{
    public int $inspections = 0;

    /**
     * @param  string|null  $processListing  Output of `ps -ax -o pid=,command=`. Null reads the live process list.
     * @param  string|null  $pluginsDirectory  Bloom's Plugins directory. Null uses BLOOM_PLUGINS_DIR or ~/Library/Application Support/Bloom/Plugins.
     */
    public function __construct(
        private ?string $processListing = null,
        private ?string $pluginsDirectory = null,
    ) {}

    public function inspect(): BloomHostSnapshot
    {
        $this->inspections++;
        $listing = $this->processListing ?? $this->readProcesses();
        [$pid, $command] = $this->bloomProcess($listing);
        $plugins = $this->pluginsDirectory ?? $this->defaultPluginsDirectory();
        $pluginJson = rtrim($plugins, '/').'/sifrious.molly/plugin.json';
        $enabledPath = rtrim($plugins, '/').'/enabled.json';
        [$pluginId, $apiVersion] = $this->manifest($pluginJson);
        $enabled = $pluginId !== null && $this->enabled($enabledPath, $pluginId);

        return new BloomHostSnapshot(
            $pid !== null,
            $pid,
            $command,
            $pluginId,
            $apiVersion,
            $enabled,
            $this->processListing === null ? 'ps -ax -o pid=,command=' : 'supplied-process-listing',
            $pluginJson,
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

    /** @return array{0: int|null, 1: string} */
    private function bloomProcess(string $listing): array
    {
        foreach (preg_split("/\r\n|\n|\r/", $listing) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/\A(\d+)\s+(.*)\z/', $line, $match) !== 1) {
                continue;
            }
            if (preg_match('#(?:Bloom(?: Dev)?\.app/|/MacOS/Bloom(?: Dev)?(?:\s|$))#', $match[2]) !== 1) {
                continue;
            }
            $pid = (int) $match[1];
            if ($pid > 1) {
                return [$pid, $match[2]];
            }
        }

        return [null, ''];
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
