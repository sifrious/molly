<?php

namespace Sifrious\Molly\Knowledge;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application;
use RuntimeException;

final class LaravelVersion
{
    public function current(?string $requested = null): string
    {
        $installed = $this->installedMajor();

        if ($requested !== null && $requested !== '' && $requested !== $installed) {
            throw new RuntimeException("KNOWLEDGE_VERSION_MISMATCH: Laravel {$installed} is installed, not Laravel {$requested}.");
        }

        return $installed;
    }

    private function installedMajor(): string
    {
        $version = null;
        if (class_exists(InstalledVersions::class)) {
            foreach (['laravel/framework', 'illuminate/support'] as $package) {
                if (InstalledVersions::isInstalled($package)) {
                    $version = InstalledVersions::getPrettyVersion($package);
                    break;
                }
            }
        }

        $version ??= class_exists(Application::class) ? Application::VERSION : null;
        if (! is_string($version) || ! preg_match('/(\d+)/', $version, $matches)) {
            throw new RuntimeException('KNOWLEDGE_VERSION_UNKNOWN: Molly could not determine the installed Laravel major version.');
        }

        return $matches[1];
    }
}
