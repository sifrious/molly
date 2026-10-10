<?php

namespace Sifrious\Molly\Acceptance;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Proves the commit of the installed sifrious/molly package.
 * An injected extra.molly-candidate.commit wins. Otherwise only a clean git HEAD counts.
 * A supplied identity is for a test that already decided the outcome. Production passes null.
 */
final class PackageIdentityReader
{
    public function __construct(private ?PackageIdentity $fixed = null) {}

    public function read(): PackageIdentity
    {
        if ($this->fixed instanceof PackageIdentity) {
            return $this->fixed;
        }

        $installed = $this->installed();
        $composer = $this->composerJson($installed['path']);
        $injected = $this->injectedCommit($composer);
        $git = $this->gitHead($installed['path']);
        $commit = null;
        $source = 'unverified';
        if ($injected !== null) {
            $commit = $injected;
            $source = 'injected';
        } elseif ($git['clean'] && $git['head'] !== null) {
            $commit = $git['head'];
            $source = 'git';
        }

        return new PackageIdentity(
            $commit,
            $source,
            $installed['version'] ?? (is_string($composer['version'] ?? null) ? $composer['version'] : null),
            $installed['reference'],
            $installed['path'],
            $installed['symlinked'],
            $installed['dist_shasum'],
            $this->lockSha256(),
            $this->artifactSha256(),
        );
    }

    /** @return array{version: ?string, reference: ?string, path: string, symlinked: bool, dist_shasum: ?string} */
    private function installed(): array
    {
        $path = PackageRoot::path();
        $version = null;
        $reference = null;
        $symlinked = false;
        if (class_exists(InstalledVersions::class)) {
            try {
                $version = InstalledVersions::getPrettyVersion('sifrious/molly');
                $reference = InstalledVersions::getReference('sifrious/molly');
                $install = InstalledVersions::getInstallPath('sifrious/molly');
                if (is_string($install) && $install !== '') {
                    $symlinked = is_link($install);
                    $real = realpath($install);
                    $path = $real !== false ? $real : $install;
                }
            } catch (Throwable) {
                $version = null;
                $reference = null;
            }
        }

        return [
            'version' => is_string($version) && $version !== '' ? $version : null,
            'reference' => is_string($reference) && $reference !== '' ? $reference : null,
            'path' => $path,
            'symlinked' => $symlinked,
            'dist_shasum' => $this->distShasum($path),
        ];
    }

    /** @return array<string, mixed> */
    private function composerJson(string $path): array
    {
        $file = rtrim($path, '/').'/composer.json';
        if (! is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  array<string, mixed>  $composer */
    private function injectedCommit(array $composer): ?string
    {
        $commit = $composer['extra']['molly-candidate']['commit'] ?? null;

        return is_string($commit) && preg_match('/\A[0-9a-f]{40}\z/', $commit) === 1 ? $commit : null;
    }

    /** @return array{head: ?string, clean: bool} */
    private function gitHead(string $path): array
    {
        if (! is_dir($path.'/.git') && ! is_file($path.'/.git')) {
            return ['head' => null, 'clean' => false];
        }
        try {
            $head = Process::path($path)->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
            $status = Process::path($path)->timeout(10)->run(['git', 'status', '--porcelain']);
        } catch (Throwable) {
            return ['head' => null, 'clean' => false];
        }
        $sha = trim($head->output());
        $valid = $head->successful() && preg_match('/\A[0-9a-f]{40}\z/', $sha) === 1;

        return [
            'head' => $valid ? $sha : null,
            'clean' => $valid && $status->successful() && trim($status->output()) === '',
        ];
    }

    private function distShasum(string $installPath): ?string
    {
        $files = [
            dirname($installPath, 2).'/composer/installed.json',
            dirname($installPath).'/composer/installed.json',
            base_path('vendor/composer/installed.json'),
        ];
        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($file), true);
            $packages = is_array($decoded) ? ($decoded['packages'] ?? $decoded) : [];
            if (! is_array($packages)) {
                continue;
            }
            foreach ($packages as $package) {
                if (! is_array($package) || ($package['name'] ?? null) !== 'sifrious/molly') {
                    continue;
                }
                $shasum = $package['dist']['shasum'] ?? null;

                return is_string($shasum) && $shasum !== '' ? $shasum : null;
            }
        }

        return null;
    }

    private function lockSha256(): ?string
    {
        $lock = base_path('composer.lock');

        return is_file($lock) ? hash_file('sha256', $lock) : null;
    }

    private function artifactSha256(): ?string
    {
        $artifact = getenv('MOLLY_CANDIDATE_ARTIFACT');

        return is_string($artifact) && $artifact !== '' && is_file($artifact) ? hash_file('sha256', $artifact) : null;
    }
}
