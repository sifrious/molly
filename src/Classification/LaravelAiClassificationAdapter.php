<?php

namespace Sifrious\Molly\Classification;

use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Throwable;

final class LaravelAiClassificationAdapter implements ClassificationAdapter
{
    public function __construct(private JevGate $gate, private DetectLaravelAiClassification $detect) {}

    public function name(): string
    {
        return 'laravel-ai.decide';
    }

    public function available(): bool
    {
        return $this->gate->enabled() && $this->detect->supportsDecide();
    }

    public function classify(array $evidence): ClassificationDecision
    {
        $status = $evidence['verification']['status'] ?? null;
        $encoded = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded)) {
            return $this->decision($evidence, false, $status, 'encoding_failed');
        }

        $model = config('molly.jev.model');
        $threshold = config('molly.jev.confidence_threshold');
        $timeout = config('molly.jev.timeout');

        if (! is_string($model) || $model === '' || ! is_numeric($threshold) || ! is_int($timeout)) {
            return $this->decision($evidence, false, $status, 'invalid_config');
        }

        try {
            $retry = Str::decide(
                $encoded,
                'Does this evidence support another bounded retry?',
                criteria: [
                    'true' => 'A bounded retry can plausibly address the supplied semantic failure evidence.',
                    'false' => 'The evidence does not justify another bounded retry and should be inspected instead.',
                ],
                threshold: (float) $threshold,
                provider: Lab::TypeSafe,
                model: $model,
                timeout: $timeout,
            );
        } catch (Throwable) {
            return $this->decision($evidence, false, $status, 'provider_failure');
        }

        return $this->decision($evidence, $retry, $status);
    }

    private function decision(array $evidence, bool $retry, mixed $status, ?string $reason = null): ClassificationDecision
    {
        return new ClassificationDecision(
            $this->name(),
            $retry ? 'retry' : 'inspect',
            null,
            array_values(array_filter([
                isset($evidence['run_id']) ? 'run:'.$evidence['run_id'] : null,
                isset($evidence['verification']['junit']) ? 'junit' : null,
                'laravel-ai:'.($this->detect->version() ?? 'unknown'),
                $reason !== null ? 'classification:'.$reason : null,
            ])),
            $status === 'passed' ? 'complete_if_required_gates_pass' : 'keep_failed',
            provider: 'typesafe',
            model: is_string(config('molly.jev.model')) ? config('molly.jev.model') : null,
            laravelAiVersion: $this->detect->version(),
            question: 'Does this evidence support another bounded retry?',
            result: $retry ? 'retry' : 'inspect',
            threshold: is_numeric(config('molly.jev.confidence_threshold')) ? (float) config('molly.jev.confidence_threshold') : null,
            fallbackReason: $reason,
            provenanceStatus: $reason === 'provider_failure' ? ClassificationDecision::PROVENANCE_PROVIDER_FAILURE : null,
        );
    }
}
