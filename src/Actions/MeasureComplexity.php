<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Complexity\Clever;
use Throwable;

class MeasureComplexity
{
    /**
     * @return array{status: string, probes: list<array<string, mixed>>, reason?: string, report?: string, detail?: string}
     */
    public function handle(string $workspace, string $evidenceDirectory): array
    {
        if (app()->environment('production')) {
            return ['status' => 'unavailable', 'probes' => [], 'reason' => 'clever_disabled'];
        }

        $originalRoot = config('molly-complexity.root');
        $originalReport = config('molly-complexity.report.path');
        $reportPath = rtrim($evidenceDirectory, '/').'/clever-'.bin2hex(random_bytes(12)).'.json';

        try {
            config(['molly-complexity.root' => $workspace, 'molly-complexity.report.path' => $reportPath]);
            $clever = app(Clever::class);

            if (! $clever->enabled()) {
                return ['status' => 'unavailable', 'probes' => [], 'reason' => 'clever_disabled'];
            }

            $probes = [];

            foreach ($clever->scan() as $result) {
                $probes[] = $result->toArray();
            }

            $statuses = array_column($probes, 'status');

            if ($probes === []) {
                return ['status' => 'unavailable', 'probes' => [], 'reason' => 'clever_no_probes'];
            }

            $status = match (true) {
                array_diff($statuses, ['ok', 'skipped']) !== [] => 'error',
                in_array('skipped', $statuses, true) => 'skipped',
                default => 'ok',
            };

            return ['status' => $status, 'probes' => $probes, 'report' => $reportPath];
        } catch (Throwable $exception) {
            return ['status' => 'error', 'probes' => [], 'reason' => 'clever_scan_failed', 'detail' => $exception->getMessage()];
        } finally {
            config(['molly-complexity.root' => $originalRoot, 'molly-complexity.report.path' => $originalReport]);
        }
    }
}
