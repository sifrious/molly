<?php

namespace Sifrious\Molly;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class Workspace
{
    public readonly string $path;

    public function __construct(string $path)
    {
        $root = realpath($path);

        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('WORKSPACE_INVALID: Choose an existing project directory.');
        }

        $this->path = $root;
    }

    public function exclusively(Closure $callback): mixed
    {
        return $this->withLock('run.lock', function () use ($callback): mixed {
            $lease = $this->withLock('checks.lock', function (): string {
                $lease = bin2hex(random_bytes(24));
                $path = $this->leasePath();
                if (file_put_contents($path, $lease, LOCK_EX) === false) {
                    throw new RuntimeException('WORKSPACE_LOCK_INVALID: Molly could not write the workspace lease.');
                }

                return $lease;
            });

            return $callback($lease);
        });
    }

    public function duringCheck(string $lease, Closure $callback): mixed
    {
        return $this->withLock('checks.lock', function () use ($lease, $callback): mixed {
            $path = $this->leasePath();
            if ($lease === '' || ! is_file($path) || ! hash_equals(file_get_contents($path), $lease)) {
                throw new RuntimeException('WORKSPACE_LEASE_EXPIRED: The check belongs to an earlier workspace run.');
            }

            return $callback();
        }, LOCK_SH);
    }

    private function leasePath(): string
    {
        $path = $this->path.'/.molly/checks.lease';
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new RuntimeException('WORKSPACE_LOCK_INVALID: The workspace lease requires a regular file.');
        }

        return $path;
    }

    public function exclusivelyForTask(string $taskId, Closure $callback): mixed
    {
        if (! Str::isUuid($taskId)) {
            throw new RuntimeException('TASK_ID_INVALID: Use a saved task UUID.');
        }

        return $this->withLock('task-'.strtolower($taskId).'.lock', $callback);
    }

    private function withLock(string $filename, Closure $callback, int $mode = LOCK_EX): mixed
    {
        $directory = $this->path.'/.molly';
        $lockPath = $directory.'/'.$filename;
        clearstatcache();

        if (is_link($directory) || is_link($lockPath)
            || (file_exists($directory) && ! is_dir($directory))
            || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new RuntimeException('WORKSPACE_LOCK_INVALID: The workspace lock requires a real directory and a regular file.');
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('WORKSPACE_LOCK_INVALID: Molly could not create the workspace lock directory.');
        }
        if (is_link($directory) || ! is_dir($directory)) {
            throw new RuntimeException('WORKSPACE_LOCK_INVALID: The workspace lock requires a real directory.');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('WORKSPACE_LOCK_INVALID: Molly could not open the workspace lock.');
        }

        try {
            if (! flock($lock, $mode | LOCK_NB)) {
                throw new RuntimeException('WORKSPACE_BUSY: Another Molly run is using this project.');
            }

            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function taskPaths(array $paths, string $testPath): array
    {
        if (! array_is_list($paths) || count(array_filter($paths, is_string(...))) !== count($paths)) {
            throw new RuntimeException('FILES_INVALID: Select a list of file paths.');
        }
        if (! str_starts_with($testPath, 'tests/') || ! str_ends_with($testPath, '.php')) {
            throw new RuntimeException('TEST_PATH_INVALID: Select a PHP test file under tests/. Molly includes the test in the files it may change.');
        }

        return in_array($testPath, $paths, true) ? $paths : [...$paths, $testPath];
    }

    /** @param list<string> $paths
     * @return array<string, ?string>
     */
    public function read(array $paths): array
    {
        if ($paths === [] || count($paths) > config('molly.max_files', 8) || count(array_unique($paths)) !== count($paths)) {
            throw new RuntimeException('FILES_INVALID: Choose between 1 and '.config('molly.max_files', 8).' different files.');
        }

        $files = [];

        foreach ($paths as $path) {
            $absolute = $this->resolve($path);
            if (is_file($absolute) && filesize($absolute) > config('molly.max_file_bytes', 65536)) {
                throw new RuntimeException("FILE_TOO_LARGE: {$path} exceeds the context limit.");
            }
            $files[$path] = file_exists($absolute) ? File::get($absolute) : null;

            if (strlen($files[$path] ?? '') > config('molly.max_file_bytes', 65536)) {
                throw new RuntimeException("FILE_TOO_LARGE: {$path} exceeds the context limit.");
            }
        }

        return $files;
    }

    /** @param list<array{path:string, content:string}> $edits
     * @param  array<string, ?string>  $before
     */
    public function apply(array $edits, array $before): void
    {
        $this->validateEdits($edits, $before);

        if ($this->read(array_keys($before)) !== $before) {
            throw new RuntimeException('WORKSPACE_CHANGED: A selected file changed while the agent was working. No proposal was applied.');
        }

        $attempted = [];
        try {
            foreach ($edits as $edit) {
                $absolute = $this->resolve($edit['path']);
                $attempted[] = $edit['path'];
                File::ensureDirectoryExists(dirname($absolute));
                File::replace($absolute, $edit['content']);
                if (File::get($absolute) !== $edit['content']) {
                    throw new RuntimeException('The file does not match the proposed contents.');
                }
            }
        } catch (Throwable $exception) {
            $failed = $this->restore($attempted, $before);
            if ($failed !== []) {
                throw new RuntimeException('WORKSPACE_ROLLBACK_FAILED: Review these files before continuing: '.implode(', ', $failed), 0, $exception);
            }
            throw new RuntimeException('WORKSPACE_WRITE_FAILED: Molly could not apply the proposal. Original file contents were restored.', 0, $exception);
        }
    }

    /**
     * @param  list<array{path: string, content: string}>  $edits
     * @param  array<string, ?string>  $before
     */
    private function validateEdits(array $edits, array $before): void
    {
        if ($edits === [] || count($edits) > count($before)) {
            throw new RuntimeException('CHANGES_INVALID: The agent must return changes within the selected files.');
        }

        $seen = [];

        foreach ($edits as $edit) {
            $path = $edit['path'] ?? null;
            $content = $edit['content'] ?? null;

            if (! is_string($path) || ! array_key_exists($path, $before) || isset($seen[$path]) || ! is_string($content)) {
                throw new RuntimeException('CHANGES_INVALID: The agent returned an unselected or repeated file.');
            }

            $this->resolve($path);

            if (strlen($content) > config('molly.max_file_bytes', 65536)) {
                throw new RuntimeException("FILE_TOO_LARGE: The proposed {$path} exceeds the file limit.");
            }

            $seen[$path] = true;
        }

    }

    /**
     * @param  list<string>  $attempted
     * @param  array<string, ?string>  $before
     * @return list<string>
     */
    private function restore(array $attempted, array $before): array
    {
        $failed = [];
        foreach (array_reverse($attempted) as $path) {
            try {
                $absolute = $this->resolve($path);
                $current = is_file($absolute) ? File::get($absolute) : null;
                if ($current === $before[$path]) {
                    continue;
                }
                if ($before[$path] === null) {
                    if (! File::delete($absolute)) {
                        throw new RuntimeException('Could not remove the new file.');
                    }
                } else {
                    File::replace($absolute, $before[$path]);
                    if (File::get($absolute) !== $before[$path]) {
                        throw new RuntimeException('Could not restore the original contents.');
                    }
                }
            } catch (Throwable) {
                $failed[] = $path;
            }
        }

        return $failed;
    }

    /** @param array<string, ?string> $before
     * @param  array<string, ?string>  $after
     * @return list<array{path:string,status:string,before_hash:?string,after_hash:?string}>
     */
    public function changes(array $before, array $after): array
    {
        $changes = [];

        foreach ($before as $path => $content) {
            $current = $after[$path];

            if ($content !== $current) {
                $changes[] = [
                    'path' => $path,
                    'status' => $content === null ? 'added' : ($current === null ? 'removed' : 'modified'),
                    'before_hash' => $content === null ? null : hash('sha256', $content),
                    'after_hash' => $current === null ? null : hash('sha256', $current),
                ];
            }
        }

        return $changes;
    }

    private function resolve(string $path): string
    {
        if (! preg_match('~\A(?:app|routes|resources|tests)/[A-Za-z0-9_./-]+\z~D', $path)) {
            throw new RuntimeException('PATH_INVALID: Select relative files under app, routes, resources, or tests.');
        }

        $absolute = $this->path;

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                throw new RuntimeException('PATH_INVALID: Hidden files and relative traversal are not allowed.');
            }

            $absolute .= '/'.$segment;

            clearstatcache(true, $absolute);
            if (is_link($absolute)) {
                throw new RuntimeException('PATH_INVALID: Selected files must not pass through symbolic links.');
            }
        }

        if (file_exists($absolute) && ! is_file($absolute)) {
            throw new RuntimeException('PATH_INVALID: Select regular files, not directories or special files.');
        }

        return $absolute;
    }
}
