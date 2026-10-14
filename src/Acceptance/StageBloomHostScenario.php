<?php

namespace Sifrious\Molly\Acceptance;

use RuntimeException;

/**
 * Stage a broken Bloom plugin install, and put the previous files back.
 *
 * The backup is written before either installed file changes. Restore copies
 * that backup over the install and deletes it only after the copy succeeds.
 */
final class StageBloomHostScenario
{
    public const BACKUP = 'sifrious.molly.scenario-backup';

    private const PARTIAL = 'sifrious.molly.scenario-backup.partial';

    /**
     * @return array{status: string, scenario: string, plugins: string, backup: string, installed: string, next: list<string>}
     */
    public function stage(string $scenario, ?string $pluginsDirectory = null): array
    {
        if (! in_array($scenario, ['incompat', 'broken'], true)) {
            throw new RuntimeException('HOST_SCENARIO_INVALID: Use incompat or broken.');
        }
        $plugins = $this->plugins($pluginsDirectory);
        $installed = $plugins.'/sifrious.molly';
        $pluginJson = $installed.'/plugin.json';
        if (! is_file($pluginJson)) {
            throw new RuntimeException('PLUGIN_NOT_INSTALLED: '.$pluginJson.' is missing. Register the Molly plugin before staging a scenario.');
        }
        $backup = $plugins.'/'.self::BACKUP;
        if (is_dir($backup)) {
            throw new RuntimeException('SCENARIO_ALREADY_STAGED: '.$backup.' already exists. Run molly:host-scenario restore before staging another one.');
        }
        $manifest = json_decode((string) file_get_contents($pluginJson), true);
        if (! is_array($manifest) || ($manifest['id'] ?? null) !== 'sifrious.molly' || ($manifest['apiVersion'] ?? null) !== 1) {
            throw new RuntimeException('PLUGIN_NOT_HEALTHY: '.$pluginJson.' must be sifrious.molly at apiVersion 1 before a scenario is staged.');
        }
        $binary = $installed.'/Surfaces.bundle/Contents/MacOS/MollySurfaces';
        if (! is_file($binary)) {
            throw new RuntimeException('PLUGIN_NOT_HEALTHY: '.$binary.' is already missing, so restore would have nothing to put back.');
        }

        $partial = $plugins.'/'.self::PARTIAL;
        $this->deleteTree($partial);
        $this->copyTree($installed, $partial);
        file_put_contents($partial.'/scenario.json', json_encode([
            'scenario' => $scenario,
            'staged_at' => gmdate('c'),
            'plugin_id' => 'sifrious.molly',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        if (! rename($partial, $backup)) {
            $this->deleteTree($partial);
            throw new RuntimeException('SCENARIO_BACKUP_FAILED: Could not save '.$backup.'. The installed plugin was not changed.');
        }

        if ($scenario === 'incompat') {
            $manifest['apiVersion'] = 2;
            $this->write($pluginJson, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        } else {
            if (! unlink($binary)) {
                throw new RuntimeException('SCENARIO_STAGE_FAILED: Could not remove '.$binary.'. Restore the backup at '.$backup.'.');
            }
        }

        return [
            'status' => 'staged',
            'scenario' => $scenario,
            'plugins' => $plugins,
            'backup' => $backup,
            'installed' => $installed,
            'next' => $this->next($scenario),
        ];
    }

    /**
     * @return array{status: string, scenario: ?string, plugins: string, backup: ?string, installed: string, next: list<string>}
     */
    public function restore(?string $pluginsDirectory = null): array
    {
        $plugins = $this->plugins($pluginsDirectory);
        $installed = $plugins.'/sifrious.molly';
        $backup = $plugins.'/'.self::BACKUP;
        $partial = $plugins.'/'.self::PARTIAL;
        if (is_dir($partial) && ! is_dir($backup)) {
            $this->deleteTree($partial);

            return $this->idle($plugins, $installed);
        }
        if (! is_dir($backup)) {
            return $this->idle($plugins, $installed);
        }
        $scenario = null;
        $marker = $backup.'/scenario.json';
        if (is_file($marker)) {
            $decoded = json_decode((string) file_get_contents($marker), true);
            $scenario = is_array($decoded) && is_string($decoded['scenario'] ?? null) ? $decoded['scenario'] : null;
        }
        if (! is_file($backup.'/plugin.json')) {
            throw new RuntimeException('SCENARIO_BACKUP_INVALID: '.$backup.'/plugin.json is missing. The installed plugin was left as it is.');
        }

        $this->copyTree($backup.'/plugin.json', $installed.'/plugin.json');
        if (is_dir($backup.'/Surfaces.bundle')) {
            $this->deleteTree($installed.'/Surfaces.bundle');
            $this->copyTree($backup.'/Surfaces.bundle', $installed.'/Surfaces.bundle');
        }
        $restoredBinary = $installed.'/Surfaces.bundle/Contents/MacOS/MollySurfaces';
        $restored = json_decode((string) file_get_contents($installed.'/plugin.json'), true);
        if (! is_array($restored) || ($restored['apiVersion'] ?? null) !== 1 || ! is_file($restoredBinary)) {
            throw new RuntimeException('SCENARIO_RESTORE_FAILED: The installed plugin is not apiVersion 1 with MollySurfaces. The backup is still at '.$backup.'.');
        }
        $this->deleteTree($backup);

        return [
            'status' => 'restored',
            'scenario' => $scenario,
            'plugins' => $plugins,
            'backup' => null,
            'installed' => $installed,
            'next' => ['Quit Bloom and open it again so it loads the restored plugin.'],
        ];
    }

    /** @return array{status: string, scenario: null, plugins: string, backup: null, installed: string, next: list<string>} */
    private function idle(string $plugins, string $installed): array
    {
        return [
            'status' => 'not_staged',
            'scenario' => null,
            'plugins' => $plugins,
            'backup' => null,
            'installed' => $installed,
            'next' => [],
        ];
    }

    /** @return list<string> */
    private function next(string $scenario): array
    {
        $observe = 'php artisan molly:host-observe diagnostics --project=PROJECT --json';
        $restore = 'php artisan molly:host-scenario restore --json';
        if ($scenario === 'incompat') {
            return [
                'Quit Bloom and open it again so it skips apiVersion 2.',
                $observe,
                $restore,
                'Quit Bloom and open it again so it loads the restored plugin.',
            ];
        }

        return [
            $observe,
            $restore,
            'Quit Bloom and open it again if it was open while the binary was missing.',
        ];
    }

    private function plugins(?string $directory): string
    {
        if (is_string($directory) && $directory !== '') {
            if (! is_dir($directory)) {
                throw new RuntimeException('PLUGINS_DIRECTORY_MISSING: '.$directory.' does not exist.');
            }

            return rtrim($directory, '/');
        }
        $override = getenv('BLOOM_PLUGINS_DIR');
        if (is_string($override) && $override !== '') {
            return rtrim($override, '/');
        }
        $home = getenv('HOME');

        return (is_string($home) ? $home : '').'/Library/Application Support/Bloom/Plugins';
    }

    private function copyTree(string $from, string $to): void
    {
        if (is_file($from)) {
            $parent = dirname($to);
            if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
                throw new RuntimeException('SCENARIO_BACKUP_FAILED: Could not create '.$parent.'.');
            }
            if (! copy($from, $to)) {
                throw new RuntimeException('SCENARIO_BACKUP_FAILED: Could not copy '.$from.' to '.$to.'.');
            }

            return;
        }
        if (! is_dir($from)) {
            throw new RuntimeException('SCENARIO_BACKUP_FAILED: '.$from.' is missing.');
        }
        if (! is_dir($to) && ! mkdir($to, 0700, true) && ! is_dir($to)) {
            throw new RuntimeException('SCENARIO_BACKUP_FAILED: Could not create '.$to.'.');
        }
        $entries = scandir($from);
        if ($entries === false) {
            throw new RuntimeException('SCENARIO_BACKUP_FAILED: Could not read '.$from.'.');
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->copyTree($from.'/'.$entry, $to.'/'.$entry);
        }
    }

    private function deleteTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            throw new RuntimeException('SCENARIO_RESTORE_FAILED: Could not read '.$path.'.');
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->deleteTree($path.'/'.$entry);
        }
        rmdir($path);
    }

    private function write(string $path, string $contents): void
    {
        $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (file_put_contents($temporary, $contents) === false || ! rename($temporary, $path)) {
            throw new RuntimeException('SCENARIO_STAGE_FAILED: Could not write '.$path.'.');
        }
    }
}
