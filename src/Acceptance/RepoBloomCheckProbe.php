<?php

namespace Sifrious\Molly\Acceptance;

use RuntimeException;

/**
 * Collects programmatic Bloom evidence. A visible macOS assertion stays
 * needs_native so a missing permission can block that observation without
 * hiding a product failure. Caller-supplied PASS records are ignored.
 */
final class RepoBloomCheckProbe
{
    public function __construct(private BloomProgrammaticAssertions $assertions) {}

    public function probe(VerificationCheckDefinition $check, VerificationContext $context): ProbeResult
    {
        if (in_array($check->id, $context->harnessBroken, true)) {
            throw new RuntimeException('PROBE_BROKEN: The verification fixture for '.$check->id.' could not run.');
        }

        return $this->assertions->probe($check, $context);
    }
}
