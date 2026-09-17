<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Console\Commands;

use Illuminate\Console\Command;
use Sifrious\Molly\Complexity\Clever;
use Sifrious\Molly\Complexity\Console\Concerns\RendersProbeResults;
use Sifrious\Molly\Complexity\Probes\Probe;
use Sifrious\Molly\Complexity\Probes\ProbeStatus;
use Sifrious\Molly\Complexity\Report\ReportRepository;
use Sifrious\Molly\Complexity\Report\ReportWriter;

use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;

/**
 * Base for the single-probe commands: run one probe, merge only its
 * section into the report, render.
 */
abstract class ProbeCommand extends Command
{
    use RendersProbeResults;

    /**
     * @return class-string<Probe>
     */
    abstract protected function probeClass(): string;

    public function handle(Clever $clever, ReportWriter $writer, ReportRepository $reports): int
    {
        if (! $clever->enabled()) {
            $this->error('Clever measurements are disabled.');

            return self::FAILURE;
        }

        $probe = $this->laravel->make($this->probeClass());
        $result = $probe->run();

        $writer->mergeOne($result);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return $result->status === ProbeStatus::Error ? self::FAILURE : self::SUCCESS;
        }

        if ($writer->startedFresh()) {
            warning('The existing report could not be read. Writing a new report.');
        }

        $this->renderResult($result);

        info(sprintf('Report updated at %s', $reports->path()));

        return $result->status === ProbeStatus::Error ? self::FAILURE : self::SUCCESS;
    }
}
