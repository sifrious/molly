<?php

namespace Tests\Fixtures\Complexity;

use Sifrious\Molly\Complexity\Probes\BaseProbe;
use Sifrious\Molly\Complexity\Probes\ProbeResult;

final class SkippedFixtureProbe extends BaseProbe
{
    public function key(): string
    {
        return 'fx-skip';
    }

    public function name(): string
    {
        return 'Fixture skipped';
    }

    public function prints(): string
    {
        return 'skipped fixture';
    }

    public function handVerify(): string
    {
        return 'true';
    }

    public function pairsWith(): string
    {
        return 'fixture';
    }

    public function caveats(): array
    {
        return [];
    }

    protected function execute(): ProbeResult
    {
        return $this->skipped('no_commits');
    }
}
