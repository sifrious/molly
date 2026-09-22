<?php

namespace Sifrious\Molly\Journal;

use RuntimeException;

/** Validate and atomically replace private journal files and Molly support files. */
class JournalWriter
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

    /**
     * Atomically replace a journal file with compare-and-swap protection.
     *
     * @param  string|false|null  $expectedHash  false = must not exist; null = skip CAS; string = must match
     */
    public function replaceFile(string $path, string $contents, string|false|null $expectedHash = null): void
    {
        $directory = dirname($path);
        $this->ensureDirectory($directory);
        $this->validateFile($path);

        $temporary = @tempnam($directory, '.journal-');
        if ($temporary === false) {
            throw new RuntimeException('JOURNAL_WRITE_FAILED: Molly could not create the journal file.');
        }

        try {
            if (dirname($temporary) !== $directory) {
                throw new RuntimeException('JOURNAL_WRITE_FAILED: Molly could not create the journal file.');
            }
            $written = @file_put_contents($temporary, $contents);
            if ($written !== strlen($contents)) {
                throw new RuntimeException('JOURNAL_WRITE_FAILED: The journal could not be written in full.');
            }
            @chmod($temporary, 0600);

            $this->validateDirectory($directory);
            $this->validateFile($path);

            $currentHash = is_file($path) ? @hash_file('sha256', $path) : false;
            if ($expectedHash === false && $currentHash !== false) {
                throw new RuntimeException('JOURNAL_WRITE_FAILED: The existing file changed during export.');
            }
            if (is_string($expectedHash) && $currentHash !== $expectedHash) {
                throw new RuntimeException('JOURNAL_WRITE_FAILED: The existing file changed during export.');
            }

            if (! @rename($temporary, $path)) {
                throw new RuntimeException('JOURNAL_WRITE_FAILED: The journal could not be replaced.');
            }
            @chmod($path, 0600);
            $this->validateFile($path);
        } catch (\Throwable $exception) {
            if (is_file($temporary) && ! is_link($temporary)) {
                @unlink($temporary);
            }
            if ($exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'JOURNAL_')) {
                throw $exception;
            }
            throw new RuntimeException('JOURNAL_WRITE_FAILED: Molly could not save the journal. The task and its attempts are unchanged.', previous: $exception);
        } finally {
            if (isset($temporary) && is_file($temporary) && ! is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** Ensure `.molly` exists privately and the local ignore rule is present without clobbering user lines. */
    public function prepareMollyDirectory(string $root): void
    {
        $directory = rtrim($root, '/').'/.molly';
        $this->ensureDirectory($directory);
        $gitignore = $directory.'/.gitignore';
        $existing = null;
        if (is_file($gitignore)) {
            $this->validateFile($gitignore);
            $existing = file_get_contents($gitignore);
            if ($existing === false) {
                throw new RuntimeException('JOURNAL_WRITE_FAILED: Molly could not read an existing journal support file.');
            }
        } else {
            $this->validateFile($gitignore);
        }

        $lines = explode("\n", rtrim($existing ?? '', "\r\n"));
        if (end($lines) === '*') {
            return;
        }

        $contents = ($existing ?? '').($existing !== null && ! str_ends_with($existing, "\n") ? "\n" : '')."*\n";
        $expected = $existing === null ? false : hash('sha256', $existing);
        $this->replaceFile($gitignore, $contents, $expected);
    }
}
