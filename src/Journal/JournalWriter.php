<?php

namespace Sifrious\Molly\Journal;

use RuntimeException;

/** Validate and create private journal directories and destinations. */
final class JournalWriter
{
    public function ensureDirectory(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
            throw new RuntimeException('JOURNAL_PATH_INVALID: Journal directories must be real directories, not links or files.');
        }
        if (! is_dir($path) && ! @mkdir($path, 0700) && ! is_dir($path)) {
            throw new RuntimeException('JOURNAL_WRITE_FAILED: Molly could not create the journal directory.');
        }
        $this->validateDirectory($path);
    }

    public function validateDirectory(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || ! is_dir($path) || realpath($path) !== $path) {
            throw new RuntimeException('JOURNAL_PATH_INVALID: Journal directories must remain inside the workspace without links.');
        }
    }

    public function validateFile(string $path): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat !== false && (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1)) {
            throw new RuntimeException('JOURNAL_PATH_INVALID: The journal destination must be a regular file without links.');
        }
    }
}
