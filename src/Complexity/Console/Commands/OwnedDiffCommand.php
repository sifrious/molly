<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Console\Commands;

use Sifrious\Molly\Complexity\Probes\OwnedDiffProbe;

final class OwnedDiffCommand extends ProbeCommand
{
    /**
     * The command signature.
     */
    protected $signature = 'clever:owned-diff {--json : Print this probe\'s result as JSON}';

    /**
     * The command description.
     */
    protected $description = 'Count code lines and files in the configured application paths.';

    protected function probeClass(): string
    {
        return OwnedDiffProbe::class;
    }
}
