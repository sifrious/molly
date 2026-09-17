<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Probes;

interface Probe
{
    /**
     * Stable report section key, talk-aligned: 'c1'..'c4'.
     */
    public function key(): string;

    /**
     * The verbatim talk name, e.g. 'The owned diff'.
     */
    public function name(): string;

    /**
     * One paragraph: what this probe prints and why it matters.
     */
    public function prints(): string;

    /**
     * The equivalent shell one-liner. The probe must recompute the chart:
     * every number this probe reports is recomputable by hand with this
     * command. that is the whole rule.
     */
    public function handVerify(): string;

    /**
     * The chart this probe recomputes, e.g. 'A1. the cloc ownership bar'.
     */
    public function pairsWith(): string;

    /**
     * @return list<string>
     */
    public function caveats(): array;

    public function run(): ProbeResult;
}
