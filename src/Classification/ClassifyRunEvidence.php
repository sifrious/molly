<?php

namespace Sifrious\Molly\Classification;

use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Verification\VerificationState;

final class ClassifyRunEvidence
{
    public function __construct(private ResolveClassificationAdapter $resolve) {}

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function handle(array $evidence, ?Run $run = null): ClassificationDecision
    {
        $adapter = $this->resolve->handle();
        $decision = $adapter->classify($evidence);
        $state = VerificationState::fromObserved($evidence['verification']['status'] ?? null);
        if ($state === VerificationState::Fail) {
            $decision = new ClassificationDecision(
                $decision->adapter,
                $decision->action,
                $decision->confidence,
                $decision->evidenceRefs,
                'keep_failed',
            );
        }

        if ($run !== null) {
            $report = $run->report ?? [];
            $report['classification'] = [
                ...$decision->toArray(),
                'adapter_selected' => $adapter->name(),
                'advisory' => true,
            ];
            $run->update(['report' => $report]);
        }

        return $decision;
    }
}
