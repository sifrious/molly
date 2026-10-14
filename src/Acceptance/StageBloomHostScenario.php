<?php

namespace Sifrious\Molly\Acceptance;

use RuntimeException;
use Sifrious\Molly\Projects\ProjectRegistry;

/**
 * Stage a broken Bloom plugin install, and put the previous files back.
 *
 * The backup is written before either installed file changes. Restore copies
 * that backup over the install and deletes it only after the copy succeeds.
 * Bloom 1599f05f reads every plugin.json under Plugins/, skips apiVersion
 * other than 1, and keeps the first manifest per id. The backup therefore
 * lives under the Molly home, not under Plugins/.
 */
final class StageBloomHostScenario
{
    public const BACKUP = 'host-scenario-backup';

    /** RC16 left this directory inside Plugins/. Restore still accepts it. */
    public const LEGACY_BACKUP = 'sifrious.molly.scenario-backup';

    /**
     * Bundle name staged in surfacesBundle.
     * PluginSurfaceLoader at Bloom 1599f05f uses this path when it is set,
     * otherwise Surfaces.bundle. The MollySurfaces binary stays in place.
     */
    public const INCOMPATIBLE_BUNDLE = 'Incompatible.bundle';

    private const PLUGIN_ID = 'sifrious.molly';

    /**
     * @return array{status: string, scenario: string, plugins: string, backup: string, installed: string, next: list<string>}
     */
    public function stage(string $scenario, ?string $pluginsDirectory = null): array
    {
        if (! in_array($scenario, ['incompat', 'broken'], true)) {
            throw new RuntimeException('HOST_SCENARIO_INVALID: Use incompat or broken.');
        }
        $plugins = $this->plugins($pluginsDirectory);
        $installed = $plugins.'/'.self::PLUGIN_ID;
        $pluginJson = $installed.'/plugin.json';
        if (! is_file($pluginJson)) {
            throw new RuntimeException('PLUGIN_NOT_INSTALLED: '.$pluginJson.' is missing. Register the Molly plugin before staging a scenario.');
        }
        $backup = $this->backupPath();
        $legacy = $this->legacyBackup($plugins);
        if (is_dir($backup) || is_dir($legacy)) {
            $existing = is_dir($backup) ? $backup : $legacy;
            throw new RuntimeException('SCENARIO_ALREADY_STAGED: '.$existing.' already exists. Run molly:host-scenario restore before staging another one.');
        }
        $manifest = json_decode((string) file_get_contents($pluginJson), true);
        if (! is_array($manifest) || ($manifest['id'] ?? null) !== self::PLUGIN_ID || ($manifest['apiVersion'] ?? null) !== 1) {
            throw new RuntimeException('PLUGIN_NOT_HEALTHY: '.$pluginJson.' must be sifrious.molly at apiVersion 1 before a scenario is staged.');
        }
        $binary = $installed.'/Surfaces.bundle/Contents/MacOS/MollySurfaces';
        if (! is_file($binary)) {
            throw new RuntimeException('PLUGIN_NOT_HEALTHY: '.$binary.' is already missing, so restore would have nothing to put back.');
        }
        $this->requireSoleManifest($plugins, $pluginJson);

        $partial = $this->partialPath();
        $this->deleteTree($partial);
        $this->copyTree($installed, $partial);
        file_put_contents($partial.'/scenario.json', json_encode([
            'scenario' => $scenario,
            'staged_at' => gmdate('c'),
            'plugin_id' => self::PLUGIN_ID,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        if (! rename($partial, $backup)) {
            $this->deleteTree($partial);
            throw new RuntimeException('SCENARIO_BACKUP_FAILED: Could not save '.$backup.'. The installed plugin was not changed.');
        }
        try {
            $this->requireSoleManifest($plugins, $pluginJson);
        } catch (RuntimeException $exception) {
            $this->deleteTree($backup);
            throw $exception;
        }

        if ($scenario === 'incompat') {
            $manifest['apiVersion'] = 2;
            $manifest['surfacesBundle'] = self::INCOMPATIBLE_BUNDLE;
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
        $installed = $plugins.'/'.self::PLUGIN_ID;
        $backup = $this->backupPath();
        $legacy = $this->legacyBackup($plugins);
        $partial = $this->partialPath();
        if (is_dir($partial)) {
            $this->deleteTree($partial);
        }
        $legacyPartial = $plugins.'/'.self::LEGACY_BACKUP.'.partial';
        if (is_dir($legacyPartial) && ! is_dir($legacy)) {
            $this->deleteTree($legacyPartial);
        }
        if (is_dir($backup) && is_dir($legacy)) {
            throw new RuntimeException('SCENARIO_BACKUP_AMBIGUOUS: '.$backup.' and '.$legacy.' both exist. The installed plugin was left as it is.');
        }
        if (! is_dir($backup) && ! is_dir($legacy)) {
            return $this->idle($plugins, $installed);
        }
        $backup = is_dir($backup) ? $backup : $legacy;
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
                'Quit Bloom and open it again so it does not load the missing '.self::INCOMPATIBLE_BUNDLE.'.',
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

    private function backupPath(): string
    {
        return (new ProjectRegistry)->home().'/'.self::BACKUP;
    }

    private function partialPath(): string
    {
        return (new ProjectRegistry)->home().'/'.self::BACKUP.'.partial';
    }

    private function legacyBackup(string $plugins): string
    {
        return $plugins.'/'.self::LEGACY_BACKUP;
    }

    private function requireSoleManifest(string $plugins, string $installedManifest): void
    {
        $duplicates = [];
        $this->collectDuplicateManifests($plugins, $installedManifest, $duplicates);
        if ($duplicates === []) {
            return;
        }

        throw new RuntimeException('SCENARIO_DUPLICATE_PLUGIN: '.implode(', ', $duplicates).' also has id '.self::PLUGIN_ID.'. Bloom keeps the first apiVersion 1 manifest under Plugins/ and would load that copy. Move it out of Plugins/ before staging.');
    }

    /** @param  list<string>  $found */
    private function collectDuplicateManifests(string $directory, string $installedManifest, array &$found): void
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('SCENARIO_DUPLICATE_PLUGIN: Could not read '.$directory.'.');
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.'/'.$entry;
            if (is_link($path)) {
                if ($entry === 'plugin.json') {
                    $this->considerManifest($path, $installedManifest, $found);
                }

                continue;
            }
            if (is_dir($path)) {
                $this->collectDuplicateManifests($path, $installedManifest, $found);

                continue;
            }
            if ($entry === 'plugin.json') {
                $this->considerManifest($path, $installedManifest, $found);
            }
        }
    }

    /** @param  list<string>  $found */
    private function considerManifest(string $path, string $installedManifest, array &$found): void
    {
        if ($this->sameFile($path, $installedManifest)) {
            return;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('SCENARIO_DUPLICATE_PLUGIN: Could not read '.$path.'.');
        }
        $decoded = json_decode($contents, true);
        if (is_array($decoded) && ($decoded['id'] ?? null) === self::PLUGIN_ID) {
            $found[] = $path;
        }
    }

    private function sameFile(string $left, string $right): bool
    {
        $realLeft = realpath($left);
        $realRight = realpath($right);
        if ($realLeft !== false && $realRight !== false) {
            return $realLeft === $realRight;
        }

        return $left === $right;
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
