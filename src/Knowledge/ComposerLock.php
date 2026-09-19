<?php

namespace Sifrious\Molly\Knowledge;

use Illuminate\Support\Facades\File;
use RuntimeException;

/** Read exact installed package versions from a project's composer.lock. */
final class ComposerLock
{
    /**
     * @return array{packages: array<string, string>, laravel: ?string, laravel_major: ?string, lock_path: string, lock_hash: ?string}
     */
    public function read(string $projectRoot): array
    {
        $root = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $lockPath = $root.'/composer.lock';
        if (! is_file($lockPath)) {
            throw new RuntimeException('COMPOSER_LOCK_MISSING: Project initialization requires composer.lock so Molly can pin graph versions.');
        }

        try {
            /** @var array<string, mixed> $lock */
            $lock = json_decode(File::get($lockPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('COMPOSER_LOCK_INVALID: composer.lock is not valid JSON.');
        }

        $packages = [];
        foreach (['packages', 'packages-dev'] as $bucket) {
            foreach ($lock[$bucket] ?? [] as $package) {
                if (! is_array($package)) {
                    continue;
                }
                $name = $package['name'] ?? null;
                $version = $package['version'] ?? null;
                if (is_string($name) && is_string($version) && $name !== '' && $version !== '') {
                    $packages[$name] = ltrim($version, 'v');
                }
            }
        }

        $laravel = $packages['laravel/framework'] ?? null;
        $major = null;
        if (is_string($laravel) && preg_match('/(\d+)/', $laravel, $matches) === 1) {
            $major = $matches[1];
        }

        return [
            'packages' => $packages,
            'laravel' => $laravel,
            'laravel_major' => $major,
            'lock_path' => $lockPath,
            'lock_hash' => hash_file('sha256', $lockPath) ?: null,
        ];
    }
}
