<?php

namespace Sifrious\Molly\Workspace;

use RuntimeException;

/** Creates a directory like File::ensureDirectoryExists, but names the path when it fails. */
final class Directory
{
    public static function ensure(string $path, int $mode = 0755): void
    {
        if (is_dir($path)) {
            return;
        }

        // Catch PHP's warning here: Laravel's handler would turn it into an exception without
        // the path, and a suppressed warning never reaches error_get_last().
        [$made, $reason] = self::attempt(fn (): bool => mkdir($path, $mode, true));
        if ($made || is_dir($path)) {
            return;
        }

        throw new RuntimeException('DIRECTORY_UNWRITABLE: Molly could not create '.$path.($reason !== '' ? ' ('.$reason.')' : '').'. Check free disk space and that the parent directory is writable.');
    }

    /**
     * Run one filesystem call and return its result with the reason PHP gave for a failure,
     * such as "Permission denied" or "No space left on device". The function name and path
     * prefix of the warning are removed, because callers name the path themselves.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return array{0: T, 1: string}
     */
    public static function attempt(callable $operation): array
    {
        $error = '';
        set_error_handler(function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });
        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        return [$result, self::reason($error)];
    }

    /**
     * The reason in a PHP filesystem warning, such as "Permission denied", without the
     * function name and path that start it. Other messages are returned trimmed.
     */
    public static function reason(string $message): string
    {
        return trim((string) preg_replace('/\A[a-z_]+\(.*\): (?:Failed to open stream: )?/s', '', $message));
    }

    /**
     * Replace $path atomically through a new private file beside it. A failure throws with
     * $code, the path, and the reason the system gave; the original file is left unchanged.
     * $beforeRename runs after the contents are on disk and may throw to cancel the replace.
     */
    public static function replaceFile(string $path, string $contents, string $code, int $mode = 0600, ?\Closure $beforeRename = null): void
    {
        $failed = fn (string $reason): RuntimeException => new RuntimeException($code.': Molly could not write '.$path.' ('.$reason.'). Check free disk space and that '.dirname($path).' is writable.');
        $temporary = dirname($path).'/.'.basename($path).'.molly-'.bin2hex(random_bytes(6));
        [$handle, $reason] = self::attempt(fn () => fopen($temporary, 'x'));
        if ($handle === false) {
            throw $failed($reason !== '' ? $reason : 'the directory is not writable');
        }

        try {
            [$written, $reason] = self::attempt(fn () => fwrite($handle, $contents));
            [$flushed, $flushReason] = self::attempt(fn (): bool => fflush($handle));
            fclose($handle);
            if ($written !== strlen($contents) || ! $flushed) {
                throw $failed(($reason ?: $flushReason) ?: 'the disk accepted only part of the contents');
            }
            @chmod($temporary, $mode);
            if ($beforeRename !== null) {
                $beforeRename();
            }
            [$renamed, $reason] = self::attempt(fn (): bool => rename($temporary, $path));
            if (! $renamed) {
                throw $failed($reason !== '' ? $reason : 'the new file could not be moved into place');
            }
        } finally {
            clearstatcache(true, $temporary);
            if (is_file($temporary) && ! is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Returns `<root>/.molly/<relative>` after checking that neither `.molly` nor any existing
     * path below it is a symbolic link or resolves outside the workspace. Nothing is created,
     * so a caller that asks for its path first never writes through a link.
     */
    public static function molly(string $root, string $relative = ''): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $workspace = realpath($root);
        if ($workspace === false || ! is_dir($workspace)) {
            throw new RuntimeException('WORKSPACE_INVALID: '.$root.' is not an existing directory. Pass --workspace with your Laravel project root.');
        }

        $path = $root;
        foreach (['.molly', ...explode('/', trim($relative, '/'))] as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                throw new RuntimeException('WORKSPACE_PATH_ESCAPE: '.$root.'/.molly/'.$relative.' uses relative traversal. Molly keeps its files inside '.$workspace.'.');
            }

            $path .= '/'.$segment;
            clearstatcache(true, $path);
            if (is_link($path)) {
                $target = realpath($path) ?: (readlink($path) ?: 'a missing target');
                throw new RuntimeException('WORKSPACE_PATH_ESCAPE: '.$path.' is a symbolic link to '.$target.'. Molly only writes real files and directories inside '.$workspace.'. Replace the link with a real directory or file, then run the command again.');
            }
            if (! file_exists($path)) {
                break;
            }

            $resolved = realpath($path);
            if ($resolved === false || ! str_starts_with($resolved, $workspace.'/')) {
                throw new RuntimeException('WORKSPACE_PATH_ESCAPE: '.$path.' resolves to '.($resolved ?: 'an unknown location').', outside '.$workspace.'. Molly only writes inside the workspace.');
            }
        }

        return rtrim($root.'/.molly/'.trim($relative, '/'), '/');
    }
}
