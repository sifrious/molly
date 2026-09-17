<?php

namespace Sifrious\Molly\Console;

use Sifrious\Molly\Models\Run;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;

class RunReport
{
    public function show(Run $run): void
    {
        $report = $run->report ?? [];
        note('Run '.$run->id.' / '.$run->status);
        note('Workspace: '.$run->workspace);
        if (! empty($report['summary'])) {
            note($report['summary']);
        }
        table(['Required check', 'Result'], [
            ['Pest', $report['verification']['status'] ?? 'Not run'],
            ['Tarpit review', $report['review']['status'] ?? (isset($report['review']['checks']) ? 'See findings below' : 'Not run')],
            ['Clever before changes', $report['complexity_before']['status'] ?? 'Not run'],
            ['Clever after changes', $report['complexity_after']['status'] ?? 'Not run'],
        ]);
        if (! empty($report['changes'])) {
            table(['Changed file', 'Status'], array_map(fn (array $change): array => [$change['path'], $change['status'] ?? 'changed'], $report['changes']));
        }
        if (! empty($report['verification']['output'])) {
            note($report['verification']['output']);
        }
        foreach ($report['review']['checks'] ?? [] as $key => $check) {
            note('Tarpit '.$key.' / '.$check['status']);
            note($check['evidence']);
        }
        foreach ($report['review']['findings'] ?? [] as $finding) {
            note($finding['severity'].' / '.$finding['classification'].' / '.$finding['path'].':'.$finding['line']);
            note($finding['problem']);
            note('Suggested change: '.$finding['recommendation']);
        }
        $this->showMeasurements($report);
        if (! empty($report['error'])) {
            error($this->describe($report['error']));
        }
        outro(match ($run->status) {
            'completed' => 'Task completed. Review the changed files before committing.',
            'running' => 'Run is marked running. An interrupted run may still have this status.',
            default => 'Task failed. Review the evidence before retrying.',
        });
    }

    /** @param array<string, mixed> $report */
    private function showMeasurements(array $report): void
    {
        note('Standalone Clever commands use the host application root or clever.root. Configure that root to match the workspace before comparing results.');
        foreach (['complexity_before' => 'Before changes', 'complexity_after' => 'After changes'] as $key => $label) {
            $measurement = $report[$key] ?? [];
            if (! empty($measurement['reason'])) {
                note($label.': '.$measurement['reason']);
            }
            foreach ($measurement['probes'] ?? [] as $probe) {
                $this->showProbe($probe, $label);
            }
            if (! empty($measurement['report'])) {
                note('Full measurements: '.$measurement['report']);
            }
        }
    }

    /** @param array<string, mixed> $probe */
    private function showProbe(array $probe, string $label): void
    {
        note($label.' / '.$probe['name'].' / '.$probe['status']);
        note($probe['headline'] ?? '');
        $commands = ['c1' => 'clever:owned-diff', 'c2' => 'clever:welds', 'c3' => 'clever:lonely-files', 'c4' => 'clever:hotspots'];
        if (isset($commands[$probe['key'] ?? ''])) {
            note('Command: php artisan '.$commands[$probe['key']]);
        }
        foreach (['skip_reason' => 'Skipped', 'hand_verify' => 'Verify by hand'] as $key => $title) {
            if (! empty($probe[$key])) {
                note($title.': '.$this->describe($probe[$key]));
            }
        }
        $metrics = [];
        foreach ($probe['metrics'] ?? [] as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $metrics[] = [(string) $name, $this->describe($value)];
            }
        }
        if ($metrics !== []) {
            table(['Measurement', 'Value'], $metrics);
        }
        foreach (['caveats', 'warnings', 'notes'] as $key) {
            foreach ($probe[$key] ?? [] as $detail) {
                note($this->describe($detail));
            }
        }
    }

    private function describe(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    }
}
