<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity;

use Sifrious\Molly\Complexity\Probes\ProbeResult;

/**
 * What MeasureComplexity needs from a scanner: whether it may run, and
 * the probe results of one scan of the configured root.
 */
interface ComplexityScanner
{
    public function enabled(): bool;

    /**
     * @return list<ProbeResult>
     */
    public function scan(): array;
}
