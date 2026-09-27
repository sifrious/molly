<?php

namespace Sifrious\Molly\Workspace;

use RuntimeException;

/** Creates a directory like File::ensureDirectoryExists, but names the path when it fails. */
final class Directory
{
    public static function ensure(string $path, int $mode = 0755): void
    {
        if (is_dir($path) || @mkdir($path, $mode, true) || is_dir($path)) {
            return;
        }

        $reason = (string) preg_replace('/\Amkdir\(\): /', '', error_get_last()['message'] ?? 'unknown error');

        throw new RuntimeException('DIRECTORY_UNWRITABLE: Molly could not create '.$path.' ('.$reason.'). Check free disk space and that the parent directory is writable.');
    }
}
