<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Sifrious\Molly\Actions\InspectHardware;
use Sifrious\Molly\Console\MollyPreflightCommand;
use Sifrious\Molly\Hardware\HardwareProbe;
use Sifrious\Molly\Hardware\HardwareSnapshot;
use Sifrious\Molly\Hardware\ProbeOutput;

/**
 * Replay one simulated fixture from tests/Fixtures/hardware through Process::fake and Http::fake.
 *
 * @return array<string, mixed> The fixture, with {workspace} resolved.
 */
function replayHardwareFixture(string $name): array
{
    $fixture = json_decode(File::get(__DIR__.'/../../Fixtures/hardware/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $workspace = sys_get_temp_dir().'/molly-hardware-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($workspace);
    $fixture['destination'] = str_replace('{workspace}', $workspace, $fixture['destination']);

    $fakes = [];
    foreach ($fixture['commands'] + ['*' => ['exit' => 127, 'stdout' => '', 'stderr' => 'not simulated']] as $pattern => $result) {
        $fakes[$pattern] = Process::result($result['stdout'], $result['stderr'], $result['exit']);
    }
    Process::preventStrayProcesses();
    Process::fake($fakes);

    $http = [];
    foreach (['/api/version', '/api/tags', '/api/ps'] as $path) {
        $response = $fixture['http'][$path] ?? null;
        $http['localhost:11434'.$path] = $response === null ? Http::failedConnection() : Http::response($response['json'], $response['status']);
    }
    Http::fake($http);
    config(['ai.providers.ollama.url' => 'http://localhost:11434']);

    return $fixture;
}

function hardwareFact(array $snapshot, string $path): array
{
    return Arr::get($snapshot['facts'], $path) ?? throw new RuntimeException("Missing fact {$path}");
}

dataset('hardware fixtures', fn (): array => array_map(
    fn (string $file): string => basename($file, '.json'),
    glob(__DIR__.'/../../Fixtures/hardware/*.json') ?: [],
));

it('labels every hardware fixture as simulated', function (string $name) {
    $fixture = json_decode(File::get(__DIR__.'/../../Fixtures/hardware/'.$name.'.json'), true);

    expect($fixture['simulated'])->toBeTrue()
        ->and($fixture['scenario'])->toBe($name)
        ->and($fixture['label'])->toBeString()->not->toBeEmpty();
})->with('hardware fixtures');

it('extracts the expected facts from each simulated fixture', function (string $name) {
    $fixture = replayHardwareFixture($name);

    $snapshot = app(InspectHardware::class)->handle($fixture['destination']);

    expect($snapshot['schema'])->toBe('molly.hardware-snapshot/1')
        ->and($snapshot['snapshot_sha256'])->toBe(HardwareSnapshot::hash($snapshot['facts']));

    foreach ($fixture['expect'] as $path => $value) {
        $fact = hardwareFact($snapshot, $path);
        expect($fact['status'])->toBe('measured', "{$name}: {$path} should be measured")
            ->and($fact['value'])->toBe($value, "{$name}: {$path}")
            ->and($fact['source'])->not->toBeEmpty();
    }

    foreach ($fixture['expect_unknown'] as $path) {
        $fact = hardwareFact($snapshot, $path);
        expect($fact['status'])->toBe('unknown', "{$name}: {$path} should be unknown")
            ->and($fact['reason'])->toBeString()->not->toBeEmpty()
            ->and($fact)->not->toHaveKey('value');
    }

    foreach ($fixture['expect_models'] ?? [] as $kind => $names) {
        expect(array_column(hardwareFact($snapshot, "ollama.{$kind}_models")['value'], 'name'))->toBe($names);
    }

    $unknownPaths = array_column($snapshot['unknowns'], 'fact');
    expect($unknownPaths)->toEqualCanonicalizing(array_values(array_filter(
        array_merge(...array_map(fn (string $group, array $facts): array => array_map(fn (string $fact): string => "{$group}.{$fact}", array_keys($facts)), array_keys($snapshot['facts']), $snapshot['facts'])),
        fn (string $path): bool => hardwareFact($snapshot, $path)['status'] === 'unknown',
    )));
})->with('hardware fixtures');

it('records source and raw value for every measured fact', function () {
    replayHardwareFixture('m3-ultra-96gb');

    $snapshot = app(InspectHardware::class)->handle(sys_get_temp_dir());

    foreach ($snapshot['facts'] as $group => $facts) {
        foreach ($facts as $name => $fact) {
            if ($fact['status'] === 'measured') {
                expect($fact)->toHaveKeys(['value', 'source', 'raw'], "{$group}.{$name}");
            }
        }
    }

    expect(hardwareFact($snapshot, 'memory.total_bytes'))->toMatchArray(['source' => 'sysctl -n hw.memsize', 'raw' => '103079215104'])
        ->and(hardwareFact($snapshot, 'memory.available_bytes')['formula'])->toBe('(free + inactive + speculative) pages × hw.pagesize')
        ->and(hardwareFact($snapshot, 'memory.available_bytes')['raw'])->toBe(['free' => 261437, 'inactive' => 2204933, 'speculative' => 7089, 'page_size_bytes' => 16384])
        ->and(hardwareFact($snapshot, 'ollama.installed_models')['value'][0])->toBe([
            'name' => 'gpt-oss:20b',
            'digest' => '17052f91a42e97930aa6e28a6c6c06a983e6a58dbb00434885a0cf5313e376f7',
            'size_bytes' => 13793441244,
        ]);
});

it('only calls the Ollama API on loopback', function () {
    replayHardwareFixture('m3-ultra-96gb');
    config(['ai.providers.ollama.url' => 'http://models.example.com:11434']);

    $snapshot = app(InspectHardware::class)->handle(sys_get_temp_dir());

    expect(hardwareFact($snapshot, 'ollama.api_url')['status'])->toBe('unknown')
        ->and(hardwareFact($snapshot, 'ollama.api_version')['status'])->toBe('unknown')
        ->and(hardwareFact($snapshot, 'ollama.installed_models')['status'])->toBe('unknown');
    Http::assertNothingSent();
});

it('resolves the destination from OLLAMA_MODELS and then the home directory', function () {
    replayHardwareFixture('m3-ultra-96gb');
    $models = sys_get_temp_dir().'/molly-ollama-models-'.bin2hex(random_bytes(4));
    putenv("OLLAMA_MODELS={$models}");

    try {
        $fromEnv = app(InspectHardware::class)->handle();
        putenv('OLLAMA_MODELS');
        $fromHome = app(InspectHardware::class)->handle();
    } finally {
        putenv('OLLAMA_MODELS');
    }

    expect(hardwareFact($fromEnv, 'disk.destination'))->toMatchArray(['value' => $models, 'source' => 'OLLAMA_MODELS environment variable'])
        ->and(hardwareFact($fromEnv, 'disk.destination_exists')['value'])->toBeFalse()
        ->and(hardwareFact($fromEnv, 'disk.existing_path')['value'])->toBe(rtrim(sys_get_temp_dir(), '/'))
        ->and(hardwareFact($fromHome, 'disk.destination'))->toMatchArray(['value' => getenv('HOME').'/.ollama/models', 'source' => 'default ~/.ollama/models']);
    Process::assertRan(fn ($process): bool => str_starts_with($process->command, 'df -kP '));
});

it('hashes the same facts to the same digest regardless of key order', function () {
    $facts = ['memory' => ['total_bytes' => ['status' => 'measured', 'value' => 1, 'source' => 's', 'raw' => '1']], 'disk' => ['free_bytes' => ['value' => 2, 'status' => 'measured']]];
    $reordered = ['disk' => ['free_bytes' => ['status' => 'measured', 'value' => 2]], 'memory' => ['total_bytes' => ['raw' => '1', 'source' => 's', 'value' => 1, 'status' => 'measured']]];

    expect(HardwareSnapshot::hash($facts))->toBe(HardwareSnapshot::hash($reordered))
        ->and(HardwareSnapshot::canonical($facts))->toBe('{"disk":{"free_bytes":{"status":"measured","value":2}},"memory":{"total_bytes":{"raw":"1","source":"s","status":"measured","value":1}}}')
        ->and(HardwareSnapshot::hash($facts))->not->toBe(HardwareSnapshot::hash(['disk' => ['free_bytes' => ['status' => 'measured', 'value' => 3]]] + $facts));
});

it('parses df rows whose mount point contains spaces', function () {
    expect(ProbeOutput::dfPortable("Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/disk6s1 100 40 60 40% /Volumes/Model Disk\n"))
        ->toBe(['device' => '/dev/disk6s1', 'total_kib' => 100, 'used_kib' => 40, 'available_kib' => 60, 'mount_point' => '/Volumes/Model Disk'])
        ->and(ProbeOutput::dfPortable('df: /nope: No such file or directory'))->toBeNull();
});

describe('molly:preflight', function () {
    beforeEach(fn () => $this->app[Kernel::class]->registerCommand($this->app->make(MollyPreflightCommand::class)));

    it('prints the snapshot as JSON and exits 0 with unknowns', function () {
        $fixture = replayHardwareFixture('probes-unknown');

        $exit = Artisan::call('molly:preflight', ['--json' => true, '--destination' => $fixture['destination']]);
        $snapshot = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0)
            ->and($snapshot['schema'])->toBe('molly.hardware-snapshot/1')
            ->and($snapshot['snapshot_sha256'])->toBe(HardwareSnapshot::hash($snapshot['facts']))
            ->and($snapshot['unknowns'])->not->toBeEmpty()
            ->and($snapshot)->toHaveKeys(['measured_at', 'host', 'facts', 'unknowns', 'canonicalization']);
    });

    it('shows a readable table and the snapshot digest', function () {
        $fixture = replayHardwareFixture('m3-ultra-96gb');

        $exit = Artisan::call('molly:preflight', ['--destination' => $fixture['destination']]);
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('memory.total_bytes')
            ->and($output)->toContain('103079215104')
            ->and($output)->toContain('sysctl -n hw.memsize')
            ->and($output)->toContain('did not choose a model')
            ->and($output)->toContain('Snapshot ');
    });

    it('exits 1 when the snapshot cannot be produced', function () {
        $this->app->instance(InspectHardware::class, new class(app(HardwareProbe::class)) extends InspectHardware
        {
            public function handle(?string $destination = null): array
            {
                throw new RuntimeException('probe crashed');
            }
        });

        $this->artisan('molly:preflight', ['--json' => true])
            ->expectsOutputToContain('preflight_failed')
            ->assertExitCode(1);
    });
});
