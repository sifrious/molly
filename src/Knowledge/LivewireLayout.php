<?php

namespace Sifrious\Molly\Knowledge;

use Illuminate\Support\Str;

/**
 * Where the workspace's installed Livewire looks for class-based components and their
 * views. Livewire 2 resolves App\Http\Livewire; Livewire 3 and 4 resolve App\Livewire.
 * Both keep component views in resources/views/livewire. When Livewire is not installed,
 * the layout is the current default, App\Livewire.
 */
final readonly class LivewireLayout
{
    public function __construct(
        public ?string $installedVersion,
        public string $classNamespace,
        public string $classDirectory,
        public string $viewDirectory = 'resources/views/livewire',
    ) {}

    /** The layout of the livewire/livewire version in composer.lock, or else vendor/composer/installed.json. */
    public static function forWorkspace(string $root): self
    {
        $version = self::installedVersion($root);
        $major = $version !== null && preg_match('/\A(\d+)\./', $version, $match) === 1 ? (int) $match[1] : null;

        return $major !== null && $major < 3
            ? new self($version, 'App\\Http\\Livewire', 'app/Http/Livewire')
            : new self($version, 'App\\Livewire', 'app/Livewire');
    }

    /**
     * The same component class in this layout when the path uses the other Livewire
     * layout, such as app/Http/Livewire/Counter.php under Livewire 3 or 4, or null.
     */
    public function relocate(string $path): ?string
    {
        $other = $this->classDirectory === 'app/Livewire' ? 'app/Http/Livewire/' : 'app/Livewire/';

        return str_starts_with($path, $other) ? $this->classDirectory.'/'.substr($path, strlen($other)) : null;
    }

    /**
     * The view Livewire renders by convention for a component class in this layout,
     * such as resources/views/livewire/user-table.blade.php for app/Livewire/UserTable.php,
     * or null when the path is not a component class here.
     */
    public function viewFor(string $classPath): ?string
    {
        $prefix = $this->classDirectory.'/';
        if (! str_starts_with($classPath, $prefix) || ! str_ends_with($classPath, '.php')) {
            return null;
        }
        $segments = explode('/', substr($classPath, strlen($prefix), -strlen('.php')));

        return $this->viewDirectory.'/'.implode('/', array_map(Str::kebab(...), $segments)).'.blade.php';
    }

    /** @return array{installed_version: ?string, class_namespace: string, class_directory: string, view_directory: string} */
    public function toArray(): array
    {
        return [
            'installed_version' => $this->installedVersion,
            'class_namespace' => $this->classNamespace,
            'class_directory' => $this->classDirectory,
            'view_directory' => $this->viewDirectory,
        ];
    }

    private static function installedVersion(string $root): ?string
    {
        $lock = json_decode((string) @file_get_contents($root.'/composer.lock'), true);
        $installed = json_decode((string) @file_get_contents($root.'/vendor/composer/installed.json'), true);
        $lists = [
            is_array($lock) ? $lock['packages'] ?? [] : [],
            is_array($lock) ? $lock['packages-dev'] ?? [] : [],
            // Composer 2 wraps the list in "packages"; Composer 1 wrote the list itself.
            is_array($installed) ? $installed['packages'] ?? $installed : [],
        ];
        foreach ($lists as $packages) {
            foreach (is_array($packages) ? $packages : [] as $package) {
                if (is_array($package) && ($package['name'] ?? null) === 'livewire/livewire' && is_string($package['version'] ?? null)) {
                    return ltrim($package['version'], 'v');
                }
            }
        }

        return null;
    }
}
