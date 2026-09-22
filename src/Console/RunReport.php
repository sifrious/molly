<?php

namespace Sifrious\Molly\Console;

use Sifrious\Molly\Models\Run;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;

class RunReport
{
    public function show(Run $run, bool $verbose = false): void
    {
        $report = $run->report ?? [];
        note('Run '.$run->id.' / '.$run->status);
        note('Workspace: '.$run->workspace);
        if (! empty($report['summary'])) {
            note($report['summary']);
        }
        $this->showBranches($report, $verbose);
        $this->showRequiredChecks($report);
        $this->showChanges($report);
        $this->showSnapshots($report, $verbose);
        $this->showVerification($report, $verbose);
        $this->showTarpit($report);
        $this->showMeasurements($report, $verbose);
        $this->showAdvice($report);
        $this->showErrors($report);
        $this->showOutcome($run->status);
    }

    /** @param array<string, mixed> $report */
    private function showRequiredChecks(array $report): void
    {
        table(['Required check', 'Result'], [
            ['Pest', $report['verification']['status'] ?? 'Not run'],
            ['Tarpit review', $report['review']['status'] ?? (isset($report['review']['checks']) ? 'See findings below' : 'Not run')],
            ['Clever before changes', $report['complexity_before']['status'] ?? 'Not run'],
            ['Clever after changes', $report['complexity_after']['status'] ?? 'Not run'],
        ]);
        foreach (['verification' => 'Pest', 'review' => 'Tarpit review'] as $key => $label) {
            foreach (['reason', 'error'] as $detail) {
                if (! empty($report[$key][$detail])) {
                    note($label.' / '.$this->describe($report[$key][$detail]));
                }
            }
        }
    }

    /** @param array<string, mixed> $report */
    private function showChanges(array $report): void
    {
        if (empty($report['changes'])) {
            return;
        }

        table(['Changed file', 'Status'], array_map(fn (array $change): array => [$change['path'], $change['status'] ?? 'changed'], $report['changes']));
    }

    /** @param array<string, mixed> $report */
    private function showVerification(array $report, bool $verbose): void
    {
        $verification = $report['verification'] ?? [];
        if (($verification['status'] ?? null) === 'passed') {
            $tests = $verification['tests'] ?? 0;
            $assertions = $verification['assertions'] ?? 0;
            note($tests.($tests === 1 ? ' test passed, ' : ' tests passed, ').$assertions.($assertions === 1 ? ' assertion.' : ' assertions.'));
        }
        if (! empty($verification['output']) && (($verification['status'] ?? null) !== 'passed' || $verbose)) {
            note($verification['output']);
        }
    }

    /** @param array<string, mixed> $report */
    private function showTarpit(array $report): void
    {
        foreach ($report['review']['checks'] ?? [] as $key => $check) {
            note('Tarpit '.$key.' / '.$check['status']);
            note($check['evidence']);
        }
        foreach ($report['review']['findings'] ?? [] as $finding) {
            note($finding['severity'].' / '.$finding['classification'].' / '.$finding['path'].':'.$finding['line']);
            note($finding['problem']);
            note('Suggested change: '.$finding['recommendation']);
        }
    }

    /** @param array<string, mixed> $report */
    private function showAdvice(array $report): void
    {
        if (! isset($report['advice'])) {
            return;
        }

        note('Saved next-step advice / '.($report['advice']['recorded_at'] ?? 'Time not recorded'));
        note($report['advice']['reason'] ?? 'Read the saved advice in the JSON report.');
        note('Advice describes the state when requested. Task commands check the current state again.');
    }

    /** @param array<string, mixed> $report */
    private function showErrors(array $report): void
    {
        if (! empty($report['error'])) {
            error($this->describe($report['error']));
        }
    }

    private function showOutcome(string $status): void
    {
        outro(match ($status) {
            'completed' => 'Task completed. Review the changed files before committing.',
            'running' => 'Run is marked running. An interrupted run may still have this status.',
            'stopped' => 'Task stopped. Review any applied changes before retrying.',
            default => 'Task failed. Review the evidence before retrying.',
        });
    }

    /** @param array<string, mixed> $report */
    private function showSnapshots(array $report, bool $verbose): void
    {
        $snapshots = $report['snapshots'] ?? [];
        if ($snapshots === []) {
            note('Component snapshots were not recorded for this run.');

            return;
        }

        $rows = [];
        foreach (['task_creation' => 'Task creation', 'before' => 'Before execution', 'after' => 'After execution'] as $key => $label) {
            $snapshot = $snapshots[$key] ?? [];
            $rows[] = [$label, $snapshot['status'] ?? 'Not recorded', $snapshot['captured_at'] ?? 'Not recorded'];
            if (! empty($snapshot['reason'])) {
                note($label.': '.$snapshot['reason']);
            }
            if (isset($snapshot['preview'])) {
                note($label.' preview: '.$snapshot['preview']['status'].'. '.$snapshot['preview']['reason']);
            }
            if ($verbose && ($snapshot['status'] ?? null) === 'captured') {
                table([$label.' file', 'SHA-256', 'Component ID'], array_map(fn (array $file): array => [
                    $file['path'], $file['sha256'] ?? 'File absent', $file['component']['id'] ?? 'Not a recognized component path',
                ], $snapshot['files']));
            }
        }
        table(['Snapshot', 'Status', 'Captured'], $rows);
        if (empty($snapshots['task_creation'])) {
            note('No task-creation snapshot is available. The original task context is unknown.');
        }

        $components = $report['components'] ?? [];
        if (($components['status'] ?? null) !== 'compared') {
            note($components['reason'] ?? 'Component changes were not recorded.');
        } elseif ($components['changes'] === []) {
            note('No recognized component source files were present in either run snapshot.');
        } else {
            table(['Component ID', 'Status'], array_map(fn (array $component): array => [$component['id'], $component['status']], $components['changes']));
        }
    }

    /** @param array<string, mixed> $report */
    private function showBranches(array $report, bool $verbose): void
    {
        if (! isset($report['mode']) && ! array_key_exists('branches', $report)) {
            return;
        }

        note('Execution mode: '.($report['mode'] ?? 'Not recorded'));
        $branches = $report['branches'] ?? [];
        if ($branches === []) {
            note('No branch results recorded.');

            return;
        }

        $rows = [];
        foreach ($branches as $branch) {
            $kind = $branch['kind'] ?? 'Unknown branch';
            $model = ($branch['provider'] ?? 'Not recorded').' / '.($branch['model'] ?? 'Not recorded');
            if ($kind === 'verification' && empty($branch['provider']) && empty($branch['model'])) {
                $model = 'Pest';
            }
            $row = [$kind, $branch['status'] ?? 'Not reported', $branch['execution_target'] ?? 'Not recorded', $model];
            if ($verbose) {
                $row[] = $branch['started_at'] ?? 'Not recorded';
                $row[] = $branch['finished_at'] ?? 'Not recorded';
            }
            $rows[] = $row;
        }
        table(['Branch', 'Status', 'Target', 'Provider / model', ...($verbose ? ['Started', 'Finished'] : [])], $rows);

        foreach ($branches as $branch) {
            $label = $branch['kind'] ?? 'Unknown branch';
            foreach (['failure_classification', 'reason', 'error'] as $key) {
                if (! empty($branch[$key])) {
                    note($label.' / '.$this->describe($branch[$key]));
                }
            }
            if (empty($branch['result_ref'])) {
                note($label.' / No result reference recorded.');
            }
            if ($verbose) {
                note($label.' branch ID: '.($branch['branch_id'] ?? 'Not recorded'));
                note('Attempt ID: '.($branch['attempt_id'] ?? 'Not recorded'));
                if (! empty($branch['result_ref'])) {
                    note('Result: '.$branch['result_ref']);
                }
            }
        }
    }

    /** @param array<string, mixed> $report */
    private function showMeasurements(array $report, bool $verbose): void
    {
        $before = collect($report['complexity_before']['probes'] ?? [])->keyBy('key')->all();
        $after = collect($report['complexity_after']['probes'] ?? [])->keyBy('key')->all();
        if ($before !== [] || $after !== []) {
            note('Clever measures code structure. Lower counts alone do not prove a simpler design.');
            note('Standalone Clever commands use the host application root or molly-complexity.root. Match that root to the workspace.');
        }
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $this->compareProbe($before[$key] ?? [], $after[$key] ?? [], $verbose);
        }
        foreach (['complexity_before' => 'Before', 'complexity_after' => 'After'] as $key => $label) {
            $measurement = $report[$key] ?? [];
            if (! empty($measurement['reason'])) {
                note($label.': '.$measurement['reason']);
            }
            if (! empty($measurement['report'])) {
                note($label.' full measurements: '.$measurement['report']);
            }
        }
        if (! $verbose && ($before !== [] || $after !== [])) {
            note('Use -v to read Clever caveats and manual verification steps.');
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function compareProbe(array $before, array $after, bool $verbose): void
    {
        $probe = $after ?: $before;
        note($probe['name'] ?? $probe['key']);
        $rows = [['Status', $before['status'] ?? 'Not run', $after['status'] ?? 'Not run']];
        $names = array_unique([...array_keys($before['metrics'] ?? []), ...array_keys($after['metrics'] ?? [])]);
        foreach ($names as $name) {
            $left = $before['metrics'][$name] ?? null;
            $right = $after['metrics'][$name] ?? null;
            if (is_int($left) || is_float($left) || is_bool($left) || is_int($right) || is_float($right) || is_bool($right)) {
                $rows[] = [str_replace('_', ' ', $name), $this->metric($left), $this->metric($right)];
            }
        }
        table(['Measurement', 'Before', 'After'], $rows);
        $commands = ['c1' => 'clever:owned-diff', 'c2' => 'clever:welds', 'c3' => 'clever:lonely-files', 'c4' => 'clever:hotspots'];
        if (isset($commands[$probe['key'] ?? ''])) {
            note('Command: php artisan '.$commands[$probe['key']]);
        }
        foreach (['Before' => $before, 'After' => $after] as $label => $value) {
            if (! empty($value['skip_reason'])) {
                note($label.' skipped: '.$this->describe($value['skip_reason']));
            }
            if ($verbose && $value !== []) {
                $this->showProbeDetails($value, $label);
            }
        }
    }

    /** @param array<string, mixed> $probe */
    private function showProbeDetails(array $probe, string $label): void
    {
        note($label.' / '.($probe['headline'] ?? $probe['status']));
        if (! empty($probe['hand_verify'])) {
            note('Verify by hand: '.$this->describe($probe['hand_verify']));
        }
        foreach ($probe['metrics'] ?? [] as $name => $value) {
            if (is_string($value)) {
                note(str_replace('_', ' ', $name).': '.$value);
            }
        }
        foreach (['caveats', 'warnings', 'notes'] as $key) {
            foreach ($probe[$key] ?? [] as $detail) {
                note($this->describe($detail));
            }
        }
    }

    private function metric(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        return is_int($value) || is_float($value) ? (string) $value : 'Not measured';
    }

    private function describe(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    }
}
