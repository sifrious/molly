<?php

namespace Sifrious\Molly\Agents;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use JsonException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Sifrious\Molly\Hardware\HardwareProbe;
use Sifrious\Molly\ModelFit\InstallationHeadroom;
use Throwable;
use TypeError;

/**
 * The one place Molly prompts the local Ollama provider. Every model call site
 * sends its agent through prompt(), which checks the configuration and the
 * model's memory first and turns provider failures into coded messages without
 * class names or paths.
 */
class LocalOllama
{
    public function __construct(private HardwareProbe $probe) {}

    public static function validate(?string $model = null): void
    {
        $model ??= config('molly.model');
        $provider = config('ai.providers.ollama', []);
        $url = $provider['url'] ?? '';
        $parts = is_string($url) ? parse_url($url) : false;

        $timeout = config('molly.timeout');

        if (($provider['driver'] ?? null) !== 'ollama'
            || ! is_array($parts)
            || ($parts['scheme'] ?? '') !== 'http'
            || ! in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || ! is_string($model) || trim($model) === ''
            || str_contains($model, 'cloud')) {
            throw new RuntimeException('LOCAL_PROVIDER_INVALID: Use a local Ollama model and a loopback HTTP URL.');
        }
        if (! is_int($timeout) || $timeout < 1) {
            throw new RuntimeException('LOCAL_PROVIDER_INVALID: molly.timeout must be a whole number of seconds, 1 or more, and it is '.json_encode($timeout).'. Set timeout in config/molly.php, then run php artisan config:clear.');
        }
    }

    /**
     * Prompt the configured local model with a structured agent and return its answer.
     *
     * @return array<string, mixed>
     */
    public function prompt(Agent $agent, string $input): array
    {
        self::validate();
        $model = (string) config('molly.model');
        $memory = $this->memory($model);
        if ($memory['status'] === 'exceeds') {
            throw new RuntimeException('MODEL_MEMORY_INSUFFICIENT: '.$memory['message']);
        }

        try {
            $response = $agent->prompt($input, provider: 'ollama', model: $model, timeout: config('molly.timeout'));
        } catch (Throwable $exception) {
            throw $this->translate($exception, $model);
        }

        return $response instanceof StructuredAgentResponse ? $response->toArray() : [];
    }

    /**
     * Compare the model with the memory molly:preflight measures, before Ollama loads it,
     * using the same InstallationHeadroom rule as the fit decision. A model Ollama already
     * holds needs no more memory. When the available memory, the
     * model's size, or the list of loaded models is unknown, the status is unknown and
     * Molly does not refuse. This reads facts only; it never pulls, loads, or unloads a model.
     *
     * @return array{status: 'loaded'|'fits'|'exceeds'|'unknown', message: string}
     */
    public function memory(string $model): array
    {
        $headroom = InstallationHeadroom::fromConfig();

        $facts = $this->probe->memoryFacts();
        $loaded = $this->listed($facts['ollama']['loaded_models'], $model);
        if (is_array($loaded)) {
            return ['status' => 'loaded', 'message' => 'Ollama already holds '.$model.' in memory, so using it needs no new memory.'];
        }

        $installed = $this->listed($facts['ollama']['installed_models'], $model);
        $size = is_array($installed) && is_int($installed['size_bytes'] ?? null) ? $installed['size_bytes'] : null;
        $available = $facts['memory']['available_bytes'];
        $available = $available['status'] === 'measured' && is_int($available['value'] ?? null) ? $available['value'] : null;
        $unknown = match (true) {
            $available === null => 'the available memory is unknown',
            $size === null => 'Ollama did not report the size of '.$model,
            default => null,
        };
        if ($unknown !== null) {
            return ['status' => 'unknown', 'message' => 'Molly could not compare '.$model.' with free memory because '.$unknown.', so it does not refuse the model. php artisan molly:preflight shows each fact and why it is unknown.'];
        }

        $needs = $model.' needs '.InstallationHeadroom::gigabytes($size).' plus '.InstallationHeadroom::gigabytes($headroom->memoryBytes).' of headroom';
        if ($headroom->fitsMemory($size, $available)) {
            return ['status' => 'fits', 'message' => $needs.', and '.InstallationHeadroom::gigabytes($available).' is available.'];
        }
        if ($loaded === null) {
            return ['status' => 'unknown', 'message' => $needs.', and only '.InstallationHeadroom::gigabytes($available).' is available, but Ollama did not say which models it holds, so Molly does not refuse the model.'];
        }

        return ['status' => 'exceeds', 'message' => $needs.', but only '.InstallationHeadroom::gigabytes($available).' is available. Molly did not ask Ollama to load it. Choose a smaller installed model with php artisan molly:setup, or free memory and try again.'];
    }

    /**
     * @param  array<string, mixed>  $fact  A measured or unknown list of models from HardwareProbe.
     * @return array<string, mixed>|false|null The model's entry, false when the measured list lacks it, or null when the list is unknown.
     */
    private function listed(array $fact, string $model): array|false|null
    {
        if ($fact['status'] !== 'measured' || ! is_array($fact['value'] ?? null)) {
            return null;
        }
        foreach ($fact['value'] as $entry) {
            if (is_array($entry) && in_array($entry['name'] ?? null, [$model, $model.':latest'], true)) {
                return $entry;
            }
        }

        return false;
    }

    /**
     * Name the provider failure with a Molly code. The message keeps the HTTP status and
     * the model name, never the provider's body, a PHP class name, or a file path.
     */
    private function translate(Throwable $exception, string $model): Throwable
    {
        $request = $this->find($exception, RequestException::class);
        if ($request instanceof RequestException) {
            $status = $request->response->status();
            $error = $request->response->json('error');
            if ($status === 404 && is_string($error) && str_contains(strtolower($error), 'not found')) {
                return new RuntimeException('MODEL_MISSING: Ollama has no model named '.$model.'. Run ollama list, or choose an installed model with php artisan molly:setup.', 0, $exception);
            }

            return new RuntimeException('PROVIDER_ERROR: Ollama answered HTTP '.$status.' for model '.$model.'. Molly used none of the reply. Check the Ollama server log, then try again.', 0, $exception);
        }

        if ($this->find($exception, ConnectionException::class) !== null) {
            if ($this->timedOut($exception)) {
                $timeout = (int) config('molly.timeout');

                return new RuntimeException('PROVIDER_TIMEOUT: Ollama did not answer for model '.$model.' within molly.timeout, '.$timeout.' '.Str::plural('second', $timeout).'. Molly stopped waiting. Try a smaller task or a faster model, or raise molly.timeout in config/molly.php.', 0, $exception);
            }

            return new RuntimeException('PROVIDER_UNREACHABLE: Molly could not connect to Ollama at '.rtrim((string) config('ai.providers.ollama.url'), '/').'. Start Ollama with ollama serve, or fix OLLAMA_URL.', 0, $exception);
        }

        $message = $exception instanceof AiException ? $exception->getMessage() : '';
        // Laravel AI passes a body that does not decode to an array straight into a typed
        // parameter, and reports an empty object as an unknown Ollama error.
        if ($exception instanceof TypeError || $exception instanceof JsonException || str_ends_with($message, 'Unknown Ollama error.')) {
            return new RuntimeException('PROVIDER_RESPONSE_INVALID: Ollama answered, but the body was not a chat response Molly can read. Molly used none of it. Check that OLLAMA_URL points at Ollama and try again.', 0, $exception);
        }
        // A successful HTTP reply whose body is Ollama's own error field.
        if (str_starts_with($message, 'Ollama Error: ')) {
            return new RuntimeException('PROVIDER_ERROR: Ollama reported an error for model '.$model.' instead of a chat response. Molly used none of the reply. Check the Ollama server log, then try again.', 0, $exception);
        }

        return $exception;
    }

    /**
     * @template T of Throwable
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    private function find(Throwable $exception, string $class): ?Throwable
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof $class) {
                return $current;
            }
        }

        return null;
    }

    /** Guzzle reports a curl timeout as "cURL error 28"; PHP streams say "timed out". */
    private function timedOut(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (preg_match('/\bcURL error 28\b|timed out/i', $current->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }
}
