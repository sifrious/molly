<?php

namespace Sifrious\Molly\Seams;

use Sifrious\Molly\Workspace;

final class PackFiles
{
    public function path(string $root, string $relative): string
    {
        if (! preg_match('~\A[a-zA-Z0-9_-]+(?:[./][a-zA-Z0-9_-]+)*\z~D', $relative)) {
            throw new SeamError('PATH_OUTSIDE_WORKSPACE', 'Use a relative path without hidden segments or traversal.', $relative);
        }
        $path = (new Workspace($root))->path;
        foreach (explode('/', $relative) as $segment) {
            $path .= '/'.$segment;
            clearstatcache(true, $path);
            if (is_link($path)) {
                throw new SeamError('PATH_OUTSIDE_WORKSPACE', 'Instruction files cannot pass through symbolic links.', $relative);
            }
        }

        return $path;
    }

    public function read(string $root, string $relative, string $code = 'INSTRUCTION_PACK_INVALID'): string
    {
        $path = $this->path($root, $relative);
        if (! is_file($path) || ! is_readable($path)) {
            throw new SeamError($code, 'A required instruction file is missing or unreadable.', $path, 'readable regular file', 'missing');
        }
        if (filesize($path) > (int) config('molly-seams.max_file_bytes', 262144)) {
            throw new SeamError($code, 'The instruction file exceeds the configured byte limit.', $path);
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || ! mb_check_encoding($bytes, 'UTF-8')) {
            throw new SeamError($code, 'Instruction files must contain readable UTF-8 text.', $path);
        }

        return $bytes;
    }

    public function json(string $bytes, string $path): array
    {
        try {
            $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SeamError('INSTRUCTION_PACK_INVALID', 'The instruction file contains invalid JSON.', $path);
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new SeamError('INSTRUCTION_PACK_INVALID', 'The instruction file must contain a JSON object.', $path);
        }

        return $value;
    }

    public function canonical(mixed $value): string
    {
        return json_encode($this->ordered($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function ordered(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->ordered(...), $value);
    }
}
