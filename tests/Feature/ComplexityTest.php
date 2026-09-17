<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Complexity\Clever;

it('preserves Clever measurements and restores host configuration', function (string $probeStatus, string $status): void {
    config(['molly-complexity.root' => '/original', 'molly-complexity.report.path' => '/original.json']);
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
            $this->seen = [config('molly-complexity.root'), config('molly-complexity.report.path')];

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
    expect(config('molly-complexity.root'))->toBe('/original');
    expect(config('molly-complexity.report.path'))->toBe('/original.json');
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
    config(['molly-complexity.root' => '/original', 'molly-complexity.report.path' => '/original.json']);
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
    expect(config('molly-complexity.root'))->toBe('/original');
    expect(config('molly-complexity.report.path'))->toBe('/original.json');
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

it('measures source without external Clever and refreshes Git observations between workspaces', function (): void {
    $directory = sys_get_temp_dir().'/molly-complexity-'.bin2hex(random_bytes(8));
    mkdir($directory.'/plain/app', 0755, true);
    mkdir($directory.'/git/app', 0755, true);
    file_put_contents($directory.'/plain/app/example.php', "<?php\n\$value = 1;\n");
    file_put_contents($directory.'/git/app/example.php', "<?php\n\$value = 1;\n\$other = 2;\n");
    config(['molly-complexity.lonely.min_lines' => 1]);
    foreach ([['git', 'init'], ['git', 'add', '.'], ['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-m', 'Initial source']] as $command) {
        Process::path($directory.'/git')->run($command)->throw();
    }

    try {
        expect(class_exists('Clever\\Clever\\Clever'))->toBeFalse();
        $action = app(MeasureComplexity::class);
        $plain = $action->handle($directory.'/plain', $directory.'/evidence');
        $git = $action->handle($directory.'/git', $directory.'/evidence');
        file_put_contents($directory.'/git/app/second.php', "<?php\n\$second = 3;\n");
        $changed = $action->handle($directory.'/git', $directory.'/evidence');

        expect($plain['status'])->toBe('skipped');
        expect(array_column($plain['probes'], 'status'))->toBe(['ok', 'ok', 'skipped', 'skipped']);
        expect($plain['probes'][0]['metrics']['files'])->toBe(1);
        expect($git['status'])->toBe('ok');
        expect(array_column($git['probes'], 'status'))->toBe(['ok', 'ok', 'ok', 'ok']);
        expect($changed['probes'][0]['metrics']['files'])->toBe(2);
        $report = json_decode(file_get_contents($git['report']), true, flags: JSON_THROW_ON_ERROR);
        expect($report['git']['available'])->toBeTrue();
        expect(array_keys($report['probes']))->toBe(['c1', 'c2', 'c3', 'c4']);
        expect(config('molly-complexity.root'))->toBeNull();
        expect(config('molly-complexity.report.path'))->toBeNull();
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('runs each bundled Clever command and writes its report', function (string $command, ?string $key): void {
    $directory = sys_get_temp_dir().'/molly-command-'.bin2hex(random_bytes(8));
    mkdir($directory.'/app', 0755, true);
    file_put_contents($directory.'/app/example.php', "<?php\n\$value = 1;\n");
    config(['molly-complexity.root' => $directory, 'molly-complexity.report.path' => $directory.'/report.json']);

    try {
        $this->artisan($command, ['--json' => true])->assertSuccessful();
        $report = json_decode(file_get_contents($directory.'/report.json'), true, flags: JSON_THROW_ON_ERROR);
        expect(array_keys($report['probes']))->toBe($key === null ? ['c1', 'c2', 'c3', 'c4'] : [$key]);
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
})->with([
    ['clever:scan', null],
    ['clever:owned-diff', 'c1'],
    ['clever:welds', 'c2'],
    ['clever:lonely-files', 'c3'],
    ['clever:hotspots', 'c4'],
]);

it('disables bundled measurements through configuration and in production', function (?bool $enabled, string $environment, bool $expected): void {
    config(['molly-complexity.enabled' => $enabled]);
    app()->instance('env', $environment);

    try {
        expect(app(Clever::class)->enabled())->toBe($expected);
    } finally {
        app()->instance('env', 'testing');
    }
})->with([
    [null, 'testing', true],
    [false, 'testing', false],
    [null, 'staging', false],
    [true, 'staging', true],
    [true, 'production', false],
]);

it('does not write reports when a registered command becomes disabled', function (string $command): void {
    config(['molly-complexity.enabled' => false]);

    $this->artisan($command)->expectsOutputToContain('Clever measurements are disabled.')->assertFailed();
})->with(['clever:scan', 'clever:owned-diff']);

it('prints measurements and verification commands for terminal readers', function (): void {
    $directory = sys_get_temp_dir().'/molly-terminal-'.bin2hex(random_bytes(8));
    mkdir($directory.'/app', 0755, true);
    file_put_contents($directory.'/app/example.php', "<?php\n\$value = 1;\n");
    config(['molly-complexity.root' => $directory, 'molly-complexity.report.path' => $directory.'/report.json']);

    try {
        $this->artisan('clever:owned-diff')
            ->expectsOutputToContain('owned diff: 2 lines across 1 files')
            ->expectsOutputToContain('Hand-verify:')
            ->assertSuccessful();
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});
