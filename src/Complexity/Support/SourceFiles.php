<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Support;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Recursive file enumeration with plain SPL. no Symfony Finder, no shell.
 * Paths in results are deterministic (sorted by root-relative path) and
 * root-relative paths always use forward slashes, so report keys are
 * identical across operating systems.
 */
final class SourceFiles
{
    private const array ALWAYS_EXCLUDE = ['vendor', 'node_modules', 'storage', '.git'];

    public function __construct(private readonly CleverConfig $config) {}

    /**
     * @param  list<string>  $relativePaths
     * @param  list<string>  $extensions
     * @return array{files: list<string>, missing: list<string>}
     */
    public function files(array $relativePaths, array $extensions): array
    {
        $root = $this->root();
        $exclude = [...self::ALWAYS_EXCLUDE, ...$this->config->excludeDirs()];

        $files = [];
        $missing = [];

        foreach ($relativePaths as $relative) {
            $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (is_file($absolute)) {
                if ($this->matches($absolute, $extensions)) {
                    $files[] = $absolute;
                }

                continue;
            }

            if (! is_dir($absolute)) {
                $missing[] = $relative;

                continue;
            }

            foreach ($this->walk($absolute, $exclude) as $file) {
                if ($this->matches($file, $extensions)) {
                    $files[] = $file;
                }
            }
        }

        // Overlapping configured paths (e.g. ['resources', 'resources/js']) can
        // enumerate the same file twice; cloc, the hand-verify tool, de-duplicates,
        // so we must too or the owned-diff count would double-report.
        $files = array_values(array_unique($files));

        usort($files, fn (string $a, string $b): int => strcmp($this->relative($a), $this->relative($b)));

        return ['files' => $files, 'missing' => $missing];
    }

    /**
     * Root-relative path with forward slashes. the report-stable form.
     */
    public function relative(string $absolutePath): string
    {
        $root = $this->root();

        $path = str_starts_with($absolutePath, $root.DIRECTORY_SEPARATOR) || str_starts_with($absolutePath, $root.'/')
            ? substr($absolutePath, strlen($root) + 1)
            : $absolutePath;

        return str_replace('\\', '/', $path);
    }

    /**
     * Lowercased extension, with `.blade.php` reported as `blade.php` so
     * the line classifier can pick the Blade comment profile.
     */
    public function extensionOf(string $path): string
    {
        $name = strtolower(basename(str_replace('\\', '/', $path)));

        if (str_ends_with($name, '.blade.php')) {
            return 'blade.php';
        }

        $position = strrpos($name, '.');

        return $position === false ? '' : substr($name, $position + 1);
    }

    /**
     * File contents, or null when unreadable or binary (NUL byte in the
     * first KiB). Callers count nulls into their probe warnings.
     */
    public function read(string $absolutePath): ?string
    {
        try {
            if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
                return null;
            }

            $contents = file_get_contents($absolutePath);
        } catch (Throwable) {
            return null;
        }

        if ($contents === false || str_contains(substr($contents, 0, 1024), "\0")) {
            return null;
        }

        return $contents;
    }

    private function root(): string
    {
        return rtrim($this->config->root(), '/\\');
    }

    /**
     * @param  list<string>  $exclude
     * @return list<string>
     */
    private function walk(string $directory, array $exclude): array
    {
        $inner = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);

        $filter = new RecursiveCallbackFilterIterator($inner, function (mixed $current) use ($exclude): bool {
            if (! $current instanceof SplFileInfo || $current->isLink()) {
                return false;
            }

            if ($current->isDir()) {
                $name = $current->getFilename();

                return ! in_array($name, $exclude, true) && ! str_starts_with($name, '.');
            }

            return true;
        });

        $files = [];

        foreach (new RecursiveIteratorIterator($filter) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @param  list<string>  $extensions
     */
    private function matches(string $path, array $extensions): bool
    {
        $extension = $this->extensionOf($path);

        if ($extension === 'blade.php') {
            return in_array('blade.php', $extensions, true) || in_array('php', $extensions, true);
        }

        return in_array($extension, $extensions, true);
    }
}
