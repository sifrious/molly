<?php

namespace Sifrious\Molly\Actions;

use Closure;
use ParseError;
use Sifrious\Molly\Agents\LocalOllama;
use Sifrious\Molly\Agents\ReadinessCheck;
use Sifrious\Molly\Hardware\HardwareProbe;
use Sifrious\Molly\ModelFit\ModelRecords;
use Throwable;

/**
 * Prove that an installed model can do Molly's work before Molly calls it ready. The
 * check runs two steps through the same LocalOllama path a task uses: a bounded
 * inference with a known answer, then a representative Molly task, a change proposal
 * for a small class followed by the seven-check Tarpit review of that change. It
 * records the latency of each step and the memory Ollama reports for the model
 * afterwards. Molly checks that the proposed file parses and defines the method; it
 * does not execute model-written code. The record passes only when both steps pass.
 */
class CheckModelReadiness
{
    public const QUESTION = 'What is 17 + 25?';

    public const ANSWER = 42;

    public const TASK = 'Add a public total() method to App\Cart that returns the sum of the price of every line as an int.';

    public const CART = <<<'PHP'
<?php

namespace App;

class Cart
{
    /** @param list<array{name: string, price: int}> $lines */
    public function __construct(private array $lines) {}
}

PHP;

    public const TEST = <<<'PHP'
<?php

use App\Cart;

it('totals the price of every line', function () {
    $cart = new Cart([['name' => 'Tea', 'price' => 10], ['name' => 'Cake', 'price' => 20]]);

    expect($cart->total())->toBe(30);
});

PHP;

    public function __construct(
        private LocalOllama $ollama,
        private GenerateChanges $generate,
        private ReviewChanges $review,
        private HardwareProbe $probe,
        private ModelRecords $records,
    ) {}

    /**
     * Run the check for one installed model and save the result as its readiness record.
     *
     * @return array<string, mixed>
     */
    public function handle(string $model, string $digest, string $runtimeVersion, string $catalogueDigest): array
    {
        $previous = ['molly.agent' => config('molly.agent'), 'molly.model' => config('molly.model')];
        config(['molly.agent' => 'ollama', 'molly.model' => $model]);

        try {
            $inference = $this->inference();
            $task = $inference['passed']
                ? $this->task()
                : ['passed' => false, 'error' => 'READINESS_SKIPPED: The bounded inference step failed, so Molly did not run the task step.'];
            $memory = $this->memory($model);
        } finally {
            config($previous);
        }

        $record = [
            'model' => $model,
            'digest' => $digest,
            'runtime' => ['name' => 'ollama', 'version' => $runtimeVersion],
            'catalogue_sha256' => $catalogueDigest,
            'checked_at' => now()->utc()->toIso8601ZuluString(),
            'passed' => $inference['passed'] && $task['passed'],
            'checks' => ['bounded_inference' => $inference, 'molly_task' => $task],
            'memory' => $memory,
        ];
        $this->records->recordReadiness($model, $record);

        return $record;
    }

    /** @return array<string, mixed> */
    private function inference(): array
    {
        [$result, $latency, $error] = $this->timed(fn (): array => $this->ollama->prompt(ReadinessCheck::make(), self::QUESTION));
        $answer = $result['answer'] ?? null;
        $passed = $error === null && is_numeric($answer) && (int) $answer === self::ANSWER;

        return [
            'passed' => $passed,
            'question' => self::QUESTION,
            'expected' => self::ANSWER,
            'answer' => $answer,
            'max_tokens' => ReadinessCheck::MAX_TOKENS,
            'timeout_s' => config('molly.timeout'),
            'latency_ms' => $latency,
            'error' => $error ?? ($passed ? null : 'READINESS_ANSWER_WRONG: The model answered '.json_encode($answer).' instead of '.self::ANSWER.'.'),
        ];
    }

    /** @return array<string, mixed> */
    private function task(): array
    {
        $before = ['app/Cart.php' => self::CART];
        [$proposal, $proposalLatency, $error] = $this->timed(fn (): array => $this->generate->handle(self::TASK, $before, 'tests/Unit/CartTest.php', readOnlyFiles: ['tests/Unit/CartTest.php' => self::TEST]));
        $step = ['passed' => false, 'task' => self::TASK, 'proposal' => ['latency_ms' => $proposalLatency], 'review' => null, 'error' => $error];
        if ($error !== null) {
            return $step;
        }

        $after = array_column($proposal['files'], 'content', 'path');
        $cart = $after['app/Cart.php'] ?? null;
        $step['proposal'] += [
            'files' => array_keys($after),
            'parses' => is_string($cart) && $this->parses($cart),
            'defines_total' => is_string($cart) && preg_match('/\bclass\s+Cart\b/', $cart) === 1 && preg_match('/\bfunction\s+total\s*\(/', $cart) === 1,
        ];
        if (! $step['proposal']['parses'] || ! $step['proposal']['defines_total']) {
            return ['error' => 'READINESS_TASK_INVALID: The proposed app/Cart.php '.(! $step['proposal']['parses'] ? 'is not valid PHP.' : 'does not define Cart::total().')] + $step;
        }

        [$review, $reviewLatency, $error] = $this->timed(fn (): array => $this->review->handle(self::TASK, $before, $after));
        $step['review'] = ['latency_ms' => $reviewLatency] + ($error === null ? ['checks' => count($review['checks']), 'findings' => count($review['findings'])] : []);

        return ['passed' => $error === null, 'error' => $error] + $step;
    }

    /** @return array<string, mixed> What Ollama and the memory probe report after the check. */
    private function memory(string $model): array
    {
        try {
            $facts = $this->probe->memoryFacts();
        } catch (Throwable) {
            return ['loaded_size_bytes' => null, 'loaded_vram_bytes' => null, 'available_bytes_after' => null];
        }
        $loaded = null;
        foreach (($facts['ollama']['loaded_models']['value'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['name'] ?? null) === $model) {
                $loaded = $entry;
            }
        }
        $available = $facts['memory']['available_bytes'];

        return [
            'loaded_size_bytes' => $loaded['size_bytes'] ?? null,
            'loaded_vram_bytes' => $loaded['size_vram_bytes'] ?? null,
            'available_bytes_after' => $available['status'] === 'measured' ? $available['value'] : null,
        ];
    }

    private function parses(string $php): bool
    {
        if (! str_starts_with($php, '<?php')) {
            return false;
        }
        try {
            token_get_all($php, TOKEN_PARSE);

            return true;
        } catch (ParseError) {
            return false;
        }
    }

    /** @return array{0: mixed, 1: int, 2: string|null} The result, the latency in milliseconds, and the coded error. */
    private function timed(Closure $step): array
    {
        $start = hrtime(true);
        try {
            $result = $step();
            $error = null;
        } catch (Throwable $exception) {
            $result = null;
            $error = preg_match('/\A[A-Z][A-Z0-9_]+: /', $exception->getMessage()) === 1
                ? $exception->getMessage()
                : 'READINESS_STEP_FAILED: '.$exception->getMessage();
        }

        return [$result, intdiv(hrtime(true) - $start, 1_000_000), $error];
    }
}
