<?php

namespace Sifrious\Molly\Classification;

use Illuminate\Support\Str;

final class LaravelAiClassificationAdapter implements ClassificationAdapter
{
    public function __construct(private DetectLaravelAiClassification $detect) {}

    public function name(): string
    {
        return 'laravel-ai.decide';
    }

    public function available(): bool
    {
        return config('molly.jev.enabled', true) === true && $this->detect->supportsDecide();
    }

    public function classify(array $evidence): ClassificationDecision
    {
        $status = $evidence['verification']['status'] ?? null;
        $encoded = json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($encoded)) {
            return $this->decision($evidence, false, $status, 'encoding_failed');
        }

        $retry = Str::decide(
            $encoded,
            'Does this evidence support another bounded retry?',
            criteria: [
                'true' => 'A bounded retry can plausibly address the supplied semantic failure evidence.',
                'false' => 'The evidence does not justify another bounded retry and should be inspected instead.',
            ],
        );

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
        );
    }
}
