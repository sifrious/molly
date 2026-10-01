<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Complexity\Clever;
use Sifrious\Molly\Complexity\ComplexityScanner;

it('preserves Clever measurements and restores host configuration', function (string $probeStatus, string $status): void {
    config(['molly-complexity.root' => '/original', 'molly-complexity.report.path' => '/original.json']);
    $probe = ['key' => 'c1', 'status' => $probeStatus, 'metrics' => ['code_lines' => 47], 'headline' => 'owned diff: 47 lines', 'hand_verify' => 'cloc app', 'caveats' => ['Line counts do not measure design quality.'], 'warnings' => [], 'skip_reason' => null];
    $scanner = new class($probe) implements ComplexityScanner
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
    app()->instance(ComplexityScanner::class, $scanner);

    $result = app(MeasureComplexity::class)->handle('/target', '/evidence');

    expect($result)->toMatchArray(['status' => $status, 'probes' => [$probe]]);
    expect($scanner->seen[0])->toBe('/target');
    expect($scanner->seen[1])->toStartWith('/evidence/clever-');
    expect(config('molly-complexity.root'))->toBe('/original');
    expect(config('molly-complexity.report.path'))->toBe('/original.json');
})->with([['ok', 'ok'], ['skipped', 'skipped'], ['error', 'error'], ['unknown', 'error']]);

it('never runs a Clever scan when disabled or in production', function (bool $enabled, string $environment): void {
    app()->instance('env', $environment);
    app()->instance(ComplexityScanner::class, new class($enabled) implements ComplexityScanner
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
    app()->instance(ComplexityScanner::class, new class implements ComplexityScanner
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
    app()->instance(ComplexityScanner::class, new class implements ComplexityScanner
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
    foreach ([['git', 'init'], ['git', 'config', 'commit.gpgsign', 'false'], ['git', 'add', '.'], ['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-m', 'Initial source']] as $command) {
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

it('measures a committed workspace in the local environment at the repository root or in a subdirectory', function (string $prefix): void {
    $repository = sys_get_temp_dir().'/molly-local-clever-'.bin2hex(random_bytes(8));
    $workspace = rtrim($repository.'/'.$prefix, '/');
    $write = function (string $path, string $contents) use ($repository): void {
        (new Filesystem)->ensureDirectoryExists(dirname($repository.'/'.$path));
        file_put_contents($repository.'/'.$path, $contents);
    };
    $commit = function (string $author, string $message) use ($repository): void {
        foreach ([['git', 'add', '-A'], ['git', '-c', 'user.name='.$author, '-c', 'user.email='.strtolower($author).'@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', $message]] as $command) {
            Process::path($repository)->run($command)->throw();
        }
    };
    $inWorkspace = fn (string $path): string => ltrim($prefix.'/'.$path, '/');

    mkdir($repository, 0755, true);
    Process::path($repository)->run(['git', 'init', '--quiet'])->throw();
    $write($inWorkspace('app/Models/Invoice.php'), "<?php\n\nnamespace App\\Models;\n\nclass Invoice\n{\n    public int \$total = 0;\n}\n");
    $write($inWorkspace('app/Http/Controllers/InvoiceController.php'), "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Models\\Invoice;\n\nclass InvoiceController\n{\n    public function show(): Invoice\n    {\n        return new Invoice();\n    }\n}\n");
    $write($inWorkspace('app/Support/Clock.php'), "<?php\n\nnamespace App\\Support;\n\nclass Clock\n{\n    public function now(): string\n    {\n        return date('c');\n    }\n}\n");
    $write($inWorkspace('routes/web.php'), "<?php\n\n\$routes = [];\n");
    if ($prefix !== '') {
        $write('tools/outside.php', "<?php\n\n\$outside = 1;\n");
    }
    $commit('Ada', 'Start the application');
    foreach (['Grace', 'Ada'] as $round => $author) {
        $write($inWorkspace('app/Support/Clock.php'), "<?php\n\nnamespace App\\Support;\n\nuse Illuminate\\Support\\Carbon;\n\nclass Clock\n{\n    public function now(): string\n    {\n        return Carbon::now()->format('c').'{$round}';\n    }\n}\n");
        if ($prefix !== '') {
            $write('tools/outside.php', "<?php\n\n\$outside = {$round};\n");
        }
        $commit($author, 'Change the clock');
    }
    config(['molly-complexity.lonely.min_lines' => 1]);
    app()->instance('env', 'local');

    try {
        $result = app(MeasureComplexity::class)->handle((string) realpath($workspace), $repository.'-evidence');
        $probes = collect($result['probes'])->keyBy('key');
        $hotspots = $probes['c4']['metrics'];
        $lonely = $probes['c3']['metrics'];

        expect($result['status'])->toBe('ok')
            ->and($probes->pluck('status')->all())->toBe(['ok', 'ok', 'ok', 'ok'])
            ->and($probes['c1']['metrics']['files'])->toBe(4)
            ->and($probes['c2']['metrics']['welded_new'])->toBe(1)
            ->and($probes['c2']['metrics']['bare_static_total'])->toBe(1)
            ->and($hotspots['commits_scanned'])->toBe(3)
            ->and($hotspots['files_missing_on_disk'])->toBe(0)
            ->and($hotspots['points'][0])->toMatchArray(['path' => 'app/Support/Clock.php', 'churn' => 3])
            ->and(collect($hotspots['points'])->pluck('path')->sort()->values()->all())->toBe(['app/Http/Controllers/InvoiceController.php', 'app/Models/Invoice.php', 'app/Support/Clock.php', 'routes/web.php'])
            ->and($lonely['lonely_total'])->toBe(3)
            ->and($lonely['filtered_missing'])->toBe(0)
            ->and(collect($lonely['top'])->pluck('path')->sort()->values()->all())->toBe(['app/Http/Controllers/InvoiceController.php', 'app/Models/Invoice.php', 'routes/web.php'])
            ->and(collect($lonely['top'])->pluck('author')->unique()->all())->toBe(['Ada']);
        $report = json_decode(file_get_contents($result['report']), true, flags: JSON_THROW_ON_ERROR);
        expect($report['environment'])->toBe('local')
            ->and($report['git']['available'])->toBeTrue()
            ->and(array_keys($report['probes']))->toBe(['c1', 'c2', 'c3', 'c4']);
    } finally {
        app()->instance('env', 'testing');
        (new Filesystem)->deleteDirectory($repository);
        (new Filesystem)->deleteDirectory($repository.'-evidence');
    }
})->with(['repository root' => [''], 'Laravel application in a subdirectory' => ['backend']]);

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
