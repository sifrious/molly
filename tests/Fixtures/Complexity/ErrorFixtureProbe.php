<?php

namespace Tests\Fixtures\Complexity;

use Sifrious\Molly\Complexity\Probes\BaseProbe;
use Sifrious\Molly\Complexity\Probes\ProbeResult;

final class ErrorFixtureProbe extends BaseProbe
{
    public function key(): string
    {
        return 'fx-error';
    }

    public function name(): string
    {
        return 'Fixture error';
    }

    public function prints(): string
    {
        return 'error fixture';
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
        throw new \RuntimeException('probe exploded');
    }
}
