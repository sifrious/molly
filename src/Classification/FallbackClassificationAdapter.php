<?php

namespace Sifrious\Molly\Classification;

final class FallbackClassificationAdapter implements ClassificationAdapter
{
    public function name(): string
    {
        return 'molly.fallback';
    }

    public function available(): bool
    {
        return true;
    }

    public function classify(array $evidence): ClassificationDecision
    {
        $status = $evidence['verification']['status'] ?? null;
        $action = $status === 'passed' ? 'inspect' : 'retry';

        return new ClassificationDecision(
            $this->name(),
            $action,
            null,
            array_values(array_filter([
                isset($evidence['run_id']) ? 'run:'.$evidence['run_id'] : null,
                isset($evidence['verification']['junit']) ? 'junit' : null,
            ])),
            $status === 'passed' ? 'complete_if_required_gates_pass' : 'keep_failed',
            provider: null,
            model: null,
            laravelAiVersion: null,
            question: null,
            result: null,
            probability: null,
            threshold: null,
            fallbackReason: null,
            provenanceStatus: ClassificationDecision::PROVENANCE_NOT_MEASURED,
        );
    }
}
