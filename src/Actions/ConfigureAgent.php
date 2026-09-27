<?php

namespace Sifrious\Molly\Actions;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Dotenv\Parser\Parser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Sifrious\Molly\Agents\LocalOllama;

class ConfigureAgent
{
    /** @return list<string> */
    public function models(): array
    {
        LocalOllama::validate('local');
        $response = Http::timeout(10)->withoutRedirecting()->get(rtrim(config('ai.providers.ollama.url'), '/').'/api/tags');
        if (! $response->successful() || ! is_array($response->json('models'))) {
            throw new RuntimeException('Could not list installed Ollama models. Check that Ollama is running.');
        }

        return array_values(array_filter(array_column($response->json('models'), 'name'), fn ($name) => is_string($name) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:\/-]{0,199}\z/', $name) && ! str_contains($name, 'cloud')));
    }

    public function handle(string $agent, ?string $model = null): void
    {
        if (! in_array($agent, ['amp', 'ollama'], true)) {
            throw new RuntimeException('Choose amp or ollama.');
        }
        $values = ['MOLLY_AGENT' => $agent];
        if ($agent === 'ollama') {
            if ($model === null || ! in_array($model, $this->models(), true)) {
                throw new RuntimeException('Choose an installed local Ollama model.');
            }
            $values['MOLLY_LOCAL_MODEL'] = $model;
        }
        $path = app()->environmentFilePath();
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new RuntimeException('The environment path must be a regular file.');
        }
        $contents = file_exists($path) ? File::get($path) : '';
        $original = $this->parseEnvironment($contents);
        foreach ($values as $key => $value) {
            $count = 0;
            $contents = preg_replace('/^(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=.*$/m', $key.'='.$value, $contents, -1, $count);
            if ($count === 0) {
                $contents .= ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n").$key.'='.$value."\n";
            }
        }
        $candidate = $this->parseEnvironment($contents);
        if (array_diff_key($original, $values) !== array_diff_key($candidate, $values)
            || array_intersect_key($candidate, $values) != $values) {
            throw new RuntimeException('ENV_LAYOUT_UNSUPPORTED: Setup cannot update this environment layout without changing other values. Edit the Molly settings manually.');
        }
        $this->replaceEnvironmentFile($path, $contents);
        config(['molly.agent' => $agent]);
        if ($model !== null && $agent === 'ollama') {
            config(['molly.model' => $model]);
        }
    }

    /** @return array<string, string|null> */
    private function parseEnvironment(string $contents): array
    {
        try {
            $values = Dotenv::parse($contents);
            $names = array_map(fn ($entry): string => $entry->getName(), (new Parser)->parse($contents));
        } catch (InvalidFileException) {
            throw new RuntimeException('ENV_INVALID: The environment file could not be parsed. Correct its syntax before running setup.');
        }
        if (count($names) !== count(array_unique($names))) {
            throw new RuntimeException('ENV_DUPLICATE_KEYS: Remove duplicate environment keys before running setup.');
        }

        return $values;
    }

    /**
     * Replace .env atomically through a temporary file beside it. File::replace() would fall
     * back to the system temporary directory when that file cannot be created, for example
     * on a full disk, and then fail with an unrelated tempnam() notice.
     */
    private function replaceEnvironmentFile(string $path, string $contents): void
    {
        $failed = fn (string $reason): RuntimeException => new RuntimeException('ENV_UNWRITABLE: Molly could not update '.$path.': '.$reason.'. Check free disk space and permissions. The file was not changed.');
        if (file_exists($path) && ! is_writable($path)) {
            throw $failed('the file is read-only');
        }

        $mode = file_exists($path) ? fileperms($path) & 0777 : 0600;
        $temporary = dirname($path).'/.'.basename($path).'.molly-'.bin2hex(random_bytes(6));
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            throw $failed('it could not create a temporary file in '.dirname($path));
        }

        try {
            $written = @fwrite($handle, $contents);
            $flushed = @fflush($handle);
            fclose($handle);
            if ($written !== strlen($contents) || ! $flushed) {
                throw $failed('the disk accepted only part of the new contents');
            }
            if (! @chmod($temporary, $mode) || ! @rename($temporary, $path)) {
                throw $failed('it could not move the temporary file into place');
            }
        } finally {
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
