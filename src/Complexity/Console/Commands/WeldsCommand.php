<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Console\Commands;

use Sifrious\Molly\Complexity\Probes\WeldedCallSitesProbe;

final class WeldsCommand extends ProbeCommand
{
    /**
     * The command signature.
     */
    protected $signature = 'clever:welds {--json : Print this probe\'s result as JSON}';

    /**
     * The command description.
     */
    protected $description = 'Count literal constructor calls, static calls, and recognized facade calls.';

    protected function probeClass(): string
    {
        return WeldedCallSitesProbe::class;
    }
}
