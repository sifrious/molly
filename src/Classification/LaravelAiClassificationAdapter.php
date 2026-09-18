<?php

namespace Sifrious\Molly\Classification;

final class LaravelAiClassificationAdapter implements ClassificationAdapter
{
    public function __construct(private DetectLaravelAiClassification $detect) {}

    public function name(): string
    {
        return 'laravel-ai.structured';
    }

    public function available(): bool
    {
        return $this->detect->supportsStructuredAgents();
    }

    public function classify(array $evidence): ClassificationDecision
    {
        $status = $evidence['verification']['status'] ?? null;
        $failed = $status !== 'passed';

        return new ClassificationDecision(
            $this->name(),
            $failed ? 'retry' : 'inspect',
            null,
            array_values(array_filter([
                isset($evidence['run_id']) ? 'run:'.$evidence['run_id'] : null,
                isset($evidence['verification']['junit']) ? 'junit' : null,
                'laravel-ai:'.($this->detect->version() ?? 'unknown'),
            ])),
            $failed ? 'keep_failed' : 'complete_if_required_gates_pass',
        );
    }
}
