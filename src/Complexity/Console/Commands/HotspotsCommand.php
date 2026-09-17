<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Console\Commands;

use Sifrious\Molly\Complexity\Probes\HotspotsProbe;

final class HotspotsCommand extends ProbeCommand
{
    /**
     * The command signature.
     */
    protected $signature = 'clever:hotspots {--json : Print this probe\'s result as JSON}';

    /**
     * The command description.
     */
    protected $description = 'List commit counts and current code lines for frequently changed PHP files.';

    protected function probeClass(): string
    {
        return HotspotsProbe::class;
    }
}
