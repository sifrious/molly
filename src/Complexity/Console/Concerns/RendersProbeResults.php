<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Console\Concerns;

use Sifrious\Molly\Complexity\Probes\ProbeResult;
use Sifrious\Molly\Complexity\Probes\ProbeStatus;
use Sifrious\Molly\Complexity\Support\MetricRows;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Shared terminal rendering: headline first (byte-identical to the report),
 * metric rows, warnings, caveats, then the hand-verify one-liner raw and
 * copy-pasteable. Never prints a score, grade, verdict, or rating.
 */
trait RendersProbeResults
{
    protected function renderResult(ProbeResult $result): void
    {
        match ($result->status) {
            ProbeStatus::Ok => info((string) $result->headline),
            ProbeStatus::Skipped => warning(sprintf('%s. Skipped: %s', $result->name, (string) $result->skipReason)),
            ProbeStatus::Error => error(sprintf('%s. Error: %s', $result->name, (string) $result->skipReason)),
        };

        if ($result->metrics !== null) {
            $rows = array_map(
                static fn (array $row): array => [($row['indent'] ? '  ' : '').$row['label'], $row['value']],
                (new MetricRows)->build($result->metrics),
            );
            table(['Measurement', 'Value'], $rows);
        }

        foreach ($result->warnings as $warning) {
            warning($warning);
        }

        foreach ($result->caveats as $caveat) {
            note($caveat);
        }

        $this->line('  Hand-verify:');

        foreach (explode("\n", $result->handVerify) as $verifyLine) {
            $this->line('  '.$verifyLine);
        }

        $this->newLine();
    }
}
