<?php

namespace Tests\Fixtures\Complexity;

use Sifrious\Molly\Complexity\Probes\BaseProbe;
use Sifrious\Molly\Complexity\Probes\ProbeResult;

final class OkFixtureProbe extends BaseProbe
{
    public function key(): string
    {
        return 'fx-ok';
    }

    public function name(): string
    {
        return 'Fixture ok';
    }

    public function prints(): string
    {
        return 'ok fixture';
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
        return $this->ok(['n' => 1], 'ok');
    }
}
