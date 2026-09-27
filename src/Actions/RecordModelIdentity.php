<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Sifrious\Molly\Agents\LocalOllama;
use Throwable;

/**
 * Ask the configured loopback Ollama server which model answered: version
 * from /api/version, details from /api/show, and the loaded copy from
 * /api/ps, with /api/tags as the digest source when the model is no longer
 * loaded. A value Ollama did not report is recorded as "unavailable".
 */
class RecordModelIdentity
{
    public const UNAVAILABLE = 'unavailable';

    /** @return array<string, mixed>|null */
    public function handle(?int $generationMs = null): ?array
    {
        if (config('molly.agent', 'ollama') !== 'ollama') {
            return null;
        }

        $model = config('molly.model');
        $identity = [
            'provider' => 'ollama',
            'model' => is_string($model) ? $model : self::UNAVAILABLE,
            'ollama_version' => self::UNAVAILABLE,
            'digest' => self::UNAVAILABLE,
            'family' => self::UNAVAILABLE,
            'parameter_size' => self::UNAVAILABLE,
            'quantization' => self::UNAVAILABLE,
            'context_length' => self::UNAVAILABLE,
            'max_context_length' => self::UNAVAILABLE,
            'size_bytes' => self::UNAVAILABLE,
            'vram_bytes' => self::UNAVAILABLE,
            'concurrency' => self::UNAVAILABLE,
            'generation_ms' => $generationMs ?? self::UNAVAILABLE,
            'sources' => [],
            'captured_at' => now()->toIso8601String(),
        ];

        try {
            LocalOllama::validate();
        } catch (Throwable $exception) {
            return [...$identity, 'sources' => ['ollama' => $exception->getMessage()]];
        }

        $version = $this->fetch($identity, 'version', fn (PendingRequest $http) => $http->get('/api/version'));
        $identity['ollama_version'] = $this->value($version['version'] ?? null);

        $show = $this->fetch($identity, 'show', fn (PendingRequest $http) => $http->post('/api/show', ['model' => $model]));
        $identity['family'] = $this->value($show['details']['family'] ?? null);
        $identity['parameter_size'] = $this->value($show['details']['parameter_size'] ?? null);
        $identity['quantization'] = $this->value($show['details']['quantization_level'] ?? null);
        foreach (is_array($show['model_info'] ?? null) ? $show['model_info'] : [] as $key => $value) {
            if (is_string($key) && str_ends_with($key, '.context_length')) {
                $identity['max_context_length'] = $this->value($value);
            }
        }

        $loaded = $this->find($this->fetch($identity, 'ps', fn (PendingRequest $http) => $http->get('/api/ps'))['models'] ?? [], $model);
        $identity['digest'] = $this->value($loaded['digest'] ?? null);
        $identity['context_length'] = $this->value($loaded['context_length'] ?? null);
        $identity['size_bytes'] = $this->value($loaded['size'] ?? null);
        $identity['vram_bytes'] = $this->value($loaded['size_vram'] ?? null);
        if ($identity['parameter_size'] === self::UNAVAILABLE) {
            $identity['parameter_size'] = $this->value($loaded['details']['parameter_size'] ?? null);
        }
        if ($identity['quantization'] === self::UNAVAILABLE) {
            $identity['quantization'] = $this->value($loaded['details']['quantization_level'] ?? null);
        }

        if ($identity['digest'] === self::UNAVAILABLE) {
            $installed = $this->find($this->fetch($identity, 'tags', fn (PendingRequest $http) => $http->get('/api/tags'))['models'] ?? [], $model);
            $identity['digest'] = $this->value($installed['digest'] ?? null);
        }

        return $identity;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    private function fetch(array &$identity, string $source, \Closure $request): array
    {
        try {
            $response = $request(Http::baseUrl(rtrim((string) config('ai.providers.ollama.url'), '/'))->acceptJson()->connectTimeout(2)->timeout(5));
            if (! $response->successful() || ! is_array($response->json())) {
                $identity['sources'][$source] = 'HTTP '.$response->status();

                return [];
            }
            $identity['sources'][$source] = 'ok';

            return $response->json();
        } catch (Throwable $exception) {
            $identity['sources'][$source] = class_basename($exception);

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function find(mixed $models, mixed $model): array
    {
        if (! is_array($models) || ! is_string($model)) {
            return [];
        }
        $names = str_contains($model, ':') ? [$model] : [$model, $model.':latest'];
        foreach ($models as $entry) {
            if (is_array($entry) && (in_array($entry['name'] ?? null, $names, true) || in_array($entry['model'] ?? null, $names, true))) {
                return $entry;
            }
        }

        return [];
    }

    private function value(mixed $value): string|int
    {
        return is_int($value) || (is_string($value) && $value !== '') ? $value : self::UNAVAILABLE;
    }
}
