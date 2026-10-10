<?php

namespace Sifrious\Molly\Acceptance;

/**
 * Reads a compiled-host observation the host already wrote.
 * A caller-supplied PASS record is not that observation.
 */
class CompiledHostBloomObserver
{
    public int $calls = 0;

    public function observe(VerificationCheckDefinition $check, VerificationContext $context): ProbeResult
    {
        $this->calls++;
        $directory = rtrim($context->projectRoot, '/');
        if ($directory === '') {
            return $this->missing($check);
        }
        $recordPath = $directory.'/acceptance-native/'.$check->id.'.json';
        if (! is_file($recordPath)) {
            return $this->missing($check);
        }
        $decoded = json_decode((string) file_get_contents($recordPath), true);
        if (! is_array($decoded)
            || ! is_string($decoded['candidate_sha'] ?? null)
            || ! is_string($decoded['assertion'] ?? null)
            || ! is_string($decoded['expected'] ?? null)
            || ! is_string($decoded['actual'] ?? null)
            || ! is_string($decoded['source_artifact'] ?? null)) {
            return $this->missing($check);
        }
        if ($decoded['candidate_sha'] !== $context->candidateSha) {
            return ProbeResult::complete(
                CheckOutcome::EvidenceIncomplete,
                'STALE_NATIVE_EVIDENCE',
                $check->id.' has a compiled-host record for a different candidate. Molly did not reuse it.',
            );
        }
        $source = $directory.'/acceptance-native/'.$decoded['source_artifact'];
        if (! is_file($source)) {
            return $this->missing($check);
        }
        $assertion = new CheckAssertion(
            $context->candidateSha,
            $context->runId,
            $decoded['assertion'],
            $decoded['expected'],
            $decoded['actual'],
            'acceptance-native/'.$decoded['source_artifact'],
            $decoded['expected'] === $decoded['actual'] ? 'pass' : 'fail',
        );
        if ($assertion->result === 'fail') {
            return ProbeResult::complete(
                CheckOutcome::ProductFail,
                'NATIVE_OBSERVATION_MISMATCH',
                $check->id.' compiled-host observation expected '.$assertion->expected.' and saw '.$assertion->actual.'.',
                [$assertion->sourceArtifact],
                [$assertion],
            );
        }

        return ProbeResult::complete(
            CheckOutcome::Pass,
            'NATIVE_OBSERVATION_MATCHED',
            $check->id.' compiled-host observation matched '.$assertion->sourceArtifact.'.',
            [$assertion->sourceArtifact],
            [$assertion],
        );
    }

    private function missing(VerificationCheckDefinition $check): ProbeResult
    {
        return ProbeResult::complete(
            CheckOutcome::BlockedPrerequisite,
            'COMPILED_HOST_NOT_OBSERVED',
            $check->id.' was allowed to observe the Bloom UI, and no compiled host observation was recorded. This is not a Molly product failure.',
        );
    }
}
