<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Probes;

use Throwable;

/**
 * Template method around every probe: timing and the shared failure
 * taxonomy live here so probes only implement execute().
 */
abstract class BaseProbe implements Probe
{
    final public function run(): ProbeResult
    {
        $start = hrtime(true);

        try {
            $result = $this->execute();
        } catch (Throwable $exception) {
            $result = $this->error($exception->getMessage());
        }

        return $result->withDurationMs(intdiv((int) (hrtime(true) - $start), 1_000_000));
    }

    abstract protected function execute(): ProbeResult;

    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $notes
     * @param  list<string>  $warnings
     */
    final protected function ok(array $metrics, string $headline, array $notes = [], array $warnings = []): ProbeResult
    {
        return new ProbeResult(
            key: $this->key(),
            name: $this->name(),
            prints: $this->prints(),
            status: ProbeStatus::Ok,
            skipReason: null,
            metrics: $metrics,
            headline: $headline,
            handVerify: $this->handVerify(),
            pairsWith: $this->pairsWith(),
            caveats: $this->caveats(),
            notes: $notes,
            warnings: $warnings,
        );
    }

    /**
     * A skipped probe still carries its hand-verify command and caveats.
     * the panel shows how to run it once the condition is fixed.
     */
    final protected function skipped(string $reason): ProbeResult
    {
        return new ProbeResult(
            key: $this->key(),
            name: $this->name(),
            prints: $this->prints(),
            status: ProbeStatus::Skipped,
            skipReason: $reason,
            metrics: null,
            headline: null,
            handVerify: $this->handVerify(),
            pairsWith: $this->pairsWith(),
            caveats: $this->caveats(),
            notes: [],
            warnings: [],
        );
    }

    final protected function error(string $message): ProbeResult
    {
        return new ProbeResult(
            key: $this->key(),
            name: $this->name(),
            prints: $this->prints(),
            status: ProbeStatus::Error,
            skipReason: $message,
            metrics: null,
            headline: null,
            handVerify: $this->handVerify(),
            pairsWith: $this->pairsWith(),
            caveats: $this->caveats(),
            notes: [],
            warnings: [],
        );
    }
}
