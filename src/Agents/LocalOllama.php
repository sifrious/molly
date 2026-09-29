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
use Throwable;
use TypeError;

/**
 * The one place Molly prompts the local Ollama provider. Every model call site
 * sends its agent through prompt(), which checks the configuration first and
 * turns provider failures into coded messages without class names or paths.
 */
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

    /**
     * Prompt the configured local model with a structured agent and return its answer.
     *
     * @return array<string, mixed>
     */
    public function prompt(Agent $agent, string $input): array
    {
        self::validate();
        $model = (string) config('molly.model');

        try {
            $response = $agent->prompt($input, provider: 'ollama', model: $model, timeout: config('molly.timeout'));
        } catch (Throwable $exception) {
            throw $this->translate($exception, $model);
        }

        return $response instanceof StructuredAgentResponse ? $response->toArray() : [];
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
