<?php

use Clever\Clever\Clever;
use Sifrious\Molly\Actions\MeasureComplexity;

it('reports unavailable when the optional Clever package is absent', function (): void {
    if (class_exists(Clever::class)) {
        $this->markTestSkipped('This case requires a host without Clever.');
    }

    $result = app(MeasureComplexity::class)->handle('/target', '/evidence');

    expect($result)->toBe(['status' => 'unavailable', 'probes' => [], 'reason' => 'clever_not_installed']);
});

it('preserves Clever measurements and restores host configuration', function (string $probeStatus, string $status): void {
    config(['clever.root' => '/original', 'clever.report.path' => '/original.json']);
    $probe = ['key' => 'c1', 'status' => $probeStatus, 'metrics' => ['code_lines' => 47], 'headline' => 'owned diff: 47 lines', 'hand_verify' => 'cloc app', 'caveats' => ['Line counts do not measure design quality.'], 'warnings' => [], 'skip_reason' => null];
    $scanner = new class($probe)
    {
        public array $seen = [];

        public function __construct(private array $probe) {}

        public function enabled(): bool
        {
            return true;
        }

        public function scan(): array
        {
            $this->seen = [config('clever.root'), config('clever.report.path')];

            return [new class($this->probe)
            {
                public function __construct(private array $probe) {}

                public function toArray(): array
                {
                    return $this->probe;
                }
            }];
        }
    };
    app()->instance(Clever::class, $scanner);

    $result = app(MeasureComplexity::class)->handle('/target', '/evidence');

    expect($result)->toMatchArray(['status' => $status, 'probes' => [$probe]]);
    expect($scanner->seen[0])->toBe('/target');
    expect($scanner->seen[1])->toStartWith('/evidence/clever-');
    expect(config('clever.root'))->toBe('/original');
    expect(config('clever.report.path'))->toBe('/original.json');
})->with([['ok', 'ok'], ['skipped', 'skipped'], ['error', 'error'], ['unknown', 'error']]);

it('never runs a Clever scan when disabled or in production', function (bool $enabled, string $environment): void {
    app()->instance('env', $environment);
    app()->instance(Clever::class, new class($enabled)
    {
        public function __construct(private bool $enabled) {}

        public function enabled(): bool
        {
            return $this->enabled;
        }

        public function scan(): array
        {
            throw new RuntimeException('A disabled scan must not run.');
        }
    });

    $result = app(MeasureComplexity::class)->handle('/target', '/evidence');

    app()->instance('env', 'testing');

    expect($result)->toBe(['status' => 'unavailable', 'probes' => [], 'reason' => 'clever_disabled']);
})->with([[false, 'testing'], [true, 'production']]);

it('reports scan failures and restores host configuration', function (): void {
    config(['clever.root' => '/original', 'clever.report.path' => '/original.json']);
    app()->instance(Clever::class, new class
    {
        public function enabled(): bool
        {
            return true;
        }

        public function scan(): array
        {
            throw new RuntimeException('Cannot write report.');
        }
    });

    $result = app(MeasureComplexity::class)->handle('/target', '/evidence');

    expect($result)->toMatchArray(['status' => 'error', 'probes' => [], 'reason' => 'clever_scan_failed', 'detail' => 'Cannot write report.']);
    expect(config('clever.root'))->toBe('/original');
    expect(config('clever.report.path'))->toBe('/original.json');
});

it('does not treat an empty Clever scan as passing measurements', function (): void {
    app()->instance(Clever::class, new class
    {
        public function enabled(): bool
        {
            return true;
        }

        public function scan(): array
        {
            return [];
        }
    });

    $result = app(MeasureComplexity::class)->handle('/target', '/evidence');

    expect($result)->toBe(['status' => 'unavailable', 'probes' => [], 'reason' => 'clever_no_probes']);
});

it('does not reuse cached workspace observations across Clever scans', function (): void {
    $resolutions = 0;
    app()->singleton(Clever::class, function () use (&$resolutions) {
        $resolutions++;

        return new class
        {
            private ?string $observedRoot = null;

            public function enabled(): bool
            {
                return true;
            }

            public function scan(): array
            {
                $this->observedRoot ??= config('clever.root');

                return [new class($this->observedRoot)
                {
                    public function __construct(private string $root) {}

                    public function toArray(): array
                    {
                        return ['key' => 'c1', 'status' => 'ok', 'metrics' => ['observed_root' => $this->root]];
                    }
                }];
            }
        };
    });
    $action = app(MeasureComplexity::class);

    $first = $action->handle('/first-workspace', '/evidence/first');
    $second = $action->handle('/second-workspace', '/evidence/second');

    expect($first['probes'][0]['metrics']['observed_root'])->toBe('/first-workspace');
    expect($second['probes'][0]['metrics']['observed_root'])->toBe('/second-workspace');
    expect($resolutions)->toBe(2);
});
