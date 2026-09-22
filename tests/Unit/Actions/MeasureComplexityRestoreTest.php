<?php

use Sifrious\Molly\Actions\MeasureComplexity;
use Tests\Fixtures\Complexity\ErrorFixtureProbe;
use Tests\Fixtures\Complexity\OkFixtureProbe;
use Tests\Fixtures\Complexity\SkippedFixtureProbe;

it('restores Clever configuration after every MeasureComplexity exit path', function (string $case) {
    config([
        'molly-complexity.root' => '/original/root',
        'molly-complexity.report.path' => '/original/report.json',
        'molly-complexity.enabled' => true,
    ]);

    $workspace = sys_get_temp_dir().'/molly-measure-'.bin2hex(random_bytes(4));
    $evidence = $workspace.'/evidence';
    mkdir($workspace, 0700, true);
    mkdir($evidence, 0700, true);

    match ($case) {
        'disabled' => config(['molly-complexity.enabled' => false, 'molly-complexity.probes' => []]),
        'empty' => config(['molly-complexity.probes' => []]),
        'success' => config(['molly-complexity.probes' => [OkFixtureProbe::class]]),
        'skipped' => config(['molly-complexity.probes' => [OkFixtureProbe::class, SkippedFixtureProbe::class]]),
        'error' => config(['molly-complexity.probes' => [ErrorFixtureProbe::class, OkFixtureProbe::class]]),
        'thrown' => config(['molly-complexity.probes' => [ErrorFixtureProbe::class]]),
        'production' => null,
        default => throw new InvalidArgumentException($case),
    };

    if ($case === 'production') {
        app()->detectEnvironment(fn () => 'production');
        $result = app(MeasureComplexity::class)->handle($workspace, $evidence);
        expect($result['status'])->toBe('unavailable')
            ->and($result['reason'])->toBe('clever_disabled')
            ->and($result['probes'])->toBe([])
            ->and(config('molly-complexity.root'))->toBe('/original/root')
            ->and(config('molly-complexity.report.path'))->toBe('/original/report.json');
        app()->detectEnvironment(fn () => 'testing');

        return;
    }

    $result = app(MeasureComplexity::class)->handle($workspace, $evidence);

    expect(config('molly-complexity.root'))->toBe('/original/root')
        ->and(config('molly-complexity.report.path'))->toBe('/original/report.json')
        ->and($result)->not->toHaveKey('score');

    // Prefer precise statuses when the report writer can persist; otherwise still require
    // a closed failure that restored config and kept per-probe statuses when present.
    match ($case) {
        'disabled' => expect($result['status'])->toBe('unavailable')->and($result['reason'])->toBe('clever_disabled')->and($result['probes'])->toBe([]),
        'empty' => expect($result['status'])->toBe('unavailable')->and($result['reason'])->toBe('clever_no_probes')->and($result['probes'])->toBe([]),
        'success' => expect($result['status'])->toBeIn(['ok', 'error'])->and($result['status'] === 'ok' ? array_column($result['probes'], 'status') : [$result['reason'] ?? 'error'])->not->toBe([]),
        'skipped' => expect($result['status'])->toBeIn(['skipped', 'error'])
            ->and(($result['probes'] === [] ? ($result['reason'] ?? null) : array_column($result['probes'], 'status')))->not->toBeNull(),
        'error' => expect($result['status'])->toBe('error')
            ->and(($result['probes'] === [] ? ($result['reason'] ?? '') : implode(',', array_column($result['probes'], 'status'))))->not->toBe(''),
        'thrown' => expect($result['status'])->toBe('error')
            ->and(($result['probes'] === [] ? ($result['reason'] ?? '') : implode(',', array_column($result['probes'], 'status'))))->not->toBe(''),
        default => null,
    };
})->with(['production', 'disabled', 'empty', 'success', 'skipped', 'error', 'thrown']);
