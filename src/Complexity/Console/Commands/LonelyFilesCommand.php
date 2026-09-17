<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Console\Commands;

use Sifrious\Molly\Complexity\Probes\LonelyFilesProbe;

final class LonelyFilesCommand extends ProbeCommand
{
    /**
     * The command signature.
     */
    protected $signature = 'clever:lonely-files {--json : Print this probe\'s result as JSON}';

    /**
     * The command description.
     */
    protected $description = 'List frequently changed PHP files with one recorded author.';

    protected function probeClass(): string
    {
        return LonelyFilesProbe::class;
    }
}
