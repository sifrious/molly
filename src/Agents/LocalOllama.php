<?php

namespace Sifrious\Molly\Agents;

use RuntimeException;

class LocalOllama
{
    public static function validate(?string $model = null): void
    {
        $model ??= config('molly.model');
        $provider = config('ai.providers.ollama', []);
        $url = $provider['url'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;

        if (($provider['driver'] ?? null) !== 'ollama'
            || ! is_array($parts)
            || ($parts['scheme'] ?? '') !== 'http'
            || ! in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || ! is_string($model) || trim($model) === ''
            || str_contains($model, 'cloud')
            || ! is_int(config('molly.timeout')) || config('molly.timeout') < 1) {
            throw new RuntimeException('LOCAL_PROVIDER_INVALID: Use a local Ollama model and a loopback HTTP URL.');
        }
    }
}
