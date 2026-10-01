<?php

use Laravel\Prompts\Prompt;
use Sifrious\Molly\Console\RunReport;
use Sifrious\Molly\Models\Run;

it('never presents skipped Tarpit Clever or verification evidence as passing', function () {
    Prompt::fake();

    $run = new Run;
    $run->forceFill([
        'id' => 'run-skipped-evidence',
        'workspace' => '/tmp/molly-workspace',
        'status' => 'failed',
        'report' => [
            'verification' => ['status' => 'skipped', 'reason' => 'Pest was not requested for this run.'],
            'review' => [
                'status' => 'skipped',
                'reason' => 'Tarpit review was unavailable.',
                'checks' => collect(range('A', 'G'))->mapWithKeys(fn (string $code): array => [
                    $code => ['status' => 'skipped', 'evidence' => 'Check '.$code.' was not available.'],
                ])->all(),
            ],
            'complexity_before' => ['status' => 'skipped', 'reason' => 'Clever before changes was unavailable.'],
            'complexity_after' => [
                'status' => 'skipped',
                'reason' => 'Clever after changes was unavailable.',
                'probes' => [[
                    'key' => 'c1',
                    'name' => 'Owned diff',
                    'status' => 'skipped',
                    'skip_reason' => 'Clever probe skipped on purpose.',
                    'metrics' => [],
                ]],
            ],
        ],
    ]);

    (new RunReport)->show($run);

    Prompt::assertOutputContains('Pest');
    Prompt::assertOutputContains('skipped');
    Prompt::assertOutputContains('Tarpit review');
    Prompt::assertOutputContains('Clever before changes');
    Prompt::assertOutputContains('Clever after changes');
    Prompt::assertOutputContains('Pest / Pest was not requested for this run.');
    Prompt::assertOutputContains('Tarpit review / Tarpit review was unavailable.');
    Prompt::assertOutputContains('Clever probe skipped on purpose.');
    Prompt::assertOutputDoesntContain('Pest / passed');
    Prompt::assertOutputDoesntContain('Tarpit review / passed');
    Prompt::assertOutputDoesntContain('Clever before changes / passed');
    Prompt::assertOutputDoesntContain('Clever after changes / passed');
});

it('keeps all seven Tarpit checks visible when recorded', function () {
    Prompt::fake();

    $checks = collect(range('A', 'G'))->mapWithKeys(fn (string $code): array => [
        $code => ['status' => 'skipped', 'evidence' => 'Evidence for '.$code.'.'],
    ])->all();

    $run = new Run;
    $run->forceFill([
        'id' => 'run-seven-checks',
        'workspace' => '/tmp/molly-workspace',
        'status' => 'failed',
        'report' => [
            'review' => ['status' => 'skipped', 'checks' => $checks],
        ],
    ]);

    (new RunReport)->show($run);

    foreach (range('A', 'G') as $code) {
        Prompt::assertOutputContains('Tarpit '.$code.' / skipped');
        Prompt::assertOutputContains('Evidence for '.$code.'.');
    }
});

it('keeps verbose manual verification details accessible for Clever probes', function () {
    Prompt::fake();

    $run = new Run;
    $run->forceFill([
        'id' => 'run-verbose-clever',
        'workspace' => '/tmp/molly-workspace',
        'status' => 'failed',
        'report' => [
            'complexity_after' => [
                'status' => 'ok',
                'probes' => [[
                    'key' => 'c4',
                    'name' => 'Hotspots',
                    'status' => 'ok',
                    'headline' => 'Hotspots recorded.',
                    'hand_verify' => 'Open the hotspot file and confirm the change by hand.',
                    'metrics' => ['files' => 1, 'note' => 'Authorship is not ownership.'],
                    'caveats' => ['Line counts do not measure design quality.'],
                ]],
            ],
        ],
    ]);

    (new RunReport)->show($run, verbose: true);

    Prompt::assertOutputContains('Verify by hand: Open the hotspot file and confirm the change by hand.');
    Prompt::assertOutputContains('Authorship is not ownership.');
    Prompt::assertOutputContains('Line counts do not measure design quality.');
    Prompt::assertOutputContains('Command: php artisan clever:hotspots');
});

it('labels Clever as advisory and shows scan failures, probe errors, and warnings without verbose mode', function () {
    Prompt::fake();

    $run = new Run;
    $run->forceFill([
        'id' => 'run-clever-errors',
        'workspace' => '/tmp/molly-workspace',
        'status' => 'completed',
        'report' => [
            'complexity_before' => ['status' => 'error', 'probes' => [], 'reason' => 'clever_scan_failed', 'detail' => 'The report directory is not writable.'],
            'complexity_after' => [
                'status' => 'error',
                'probes' => [[
                    'key' => 'c4',
                    'name' => 'The hotspots',
                    'status' => 'error',
                    'skip_reason' => 'Git log failed after the repository checks passed.',
                    'metrics' => null,
                    'warnings' => ['The shallow clone contains partial history.'],
                    'caveats' => ['Churn counts commits that touch a path.'],
                ]],
            ],
        ],
    ]);

    (new RunReport)->show($run);

    Prompt::assertOutputContains('Clever before changes (advisory)');
    Prompt::assertOutputContains('Clever after changes (advisory)');
    Prompt::assertOutputContains('Before: clever_scan_failed');
    Prompt::assertOutputContains('Before detail: The report directory is not writable.');
    Prompt::assertOutputContains('After error: Git log failed after the repository checks passed.');
    Prompt::assertOutputContains('After warning: The shallow clone contains partial history.');
    Prompt::assertOutputDoesntContain('After skipped: Git log failed');
    Prompt::assertOutputDoesntContain('Churn counts commits that touch a path.');
});
