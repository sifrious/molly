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
}
