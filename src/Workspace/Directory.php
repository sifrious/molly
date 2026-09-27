<?php

namespace Sifrious\Molly\Workspace;

use RuntimeException;

/** Creates a directory like File::ensureDirectoryExists, but names the path when it fails. */
final class Directory
{
    public static function ensure(string $path, int $mode = 0755): void
    {
        error_clear_last();
        if (is_dir($path) || @mkdir($path, $mode, true) || is_dir($path)) {
            return;
        }

        // Laravel's error handler swallows suppressed warnings, so PHP's reason is not always recorded.
        $error = error_get_last()['message'] ?? '';
        $reason = str_starts_with($error, 'mkdir(): ') ? ' ('.substr($error, 9).')' : '';

        throw new RuntimeException('DIRECTORY_UNWRITABLE: Molly could not create '.$path.$reason.'. Check free disk space and that the parent directory is writable.');
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
