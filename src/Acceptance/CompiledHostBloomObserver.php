<?php

namespace Sifrious\Molly\Acceptance;

/**
 * Observes a native Bloom UI check after its permission is granted.
 * Without a recorded observation, the compiled host is still unproven.
 */
final class CompiledHostBloomObserver
{
    public function observe(VerificationCheckDefinition $check, VerificationContext $context): ProbeResult
    {
        if (isset($context->nativeObservations[$check->id])) {
            return ProbeResult::fromArray($context->nativeObservations[$check->id]);
        }

        return ProbeResult::complete(
            CheckOutcome::BlockedPrerequisite,
            'COMPILED_HOST_NOT_OBSERVED',
            $check->id.' was allowed to observe the Bloom UI, and no compiled host observation was recorded. This is not a Molly product failure.',
        );
    }
}
