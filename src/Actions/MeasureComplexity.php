<?php

namespace Sifrious\Molly\Actions;

use Clever\Clever\Clever;
use Throwable;

class MeasureComplexity
{
    /**
     * @return array{status: string, probes: list<array<string, mixed>>, reason?: string, report?: string, detail?: string}
     */
    public function handle(string $workspace, string $evidenceDirectory): array
    {
        if (! class_exists(Clever::class) && ! app()->bound(Clever::class)) {
            return ['status' => 'unavailable', 'probes' => [], 'reason' => 'clever_not_installed'];
        }

        if (app()->environment('production')) {
            return ['status' => 'unavailable', 'probes' => [], 'reason' => 'clever_disabled'];
        }

        $originalRoot = config('clever.root');
        $originalReport = config('clever.report.path');
        $reportPath = rtrim($evidenceDirectory, '/').'/clever-'.bin2hex(random_bytes(12)).'.json';

        try {
            $parameters = isset(app()->getBindings()[Clever::class]) ? ['app' => app()] : [];
            $clever = app(Clever::class, $parameters);

            if (! $clever->enabled()) {
                return ['status' => 'unavailable', 'probes' => [], 'reason' => 'clever_disabled'];
            }

            config(['clever.root' => $workspace, 'clever.report.path' => $reportPath]);
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
            config(['clever.root' => $originalRoot, 'clever.report.path' => $originalReport]);
        }
    }
}
