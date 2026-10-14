<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Sifrious\Molly\Acceptance\BloomHostInspector;
use Sifrious\Molly\Acceptance\HostObservationRecorder;
use Sifrious\Molly\Acceptance\PackageIdentity;
use Sifrious\Molly\Acceptance\PackageIdentityReader;
use Sifrious\Molly\Acceptance\StageBloomHostScenario;
use Sifrious\Molly\Actions\ListMollyProjects;
use Sifrious\Molly\Actions\ListTasks;
use Sifrious\Molly\Projects\ProjectRegistry;

function scenarioPlugins(): string
{
    $plugins = sys_get_temp_dir().'/molly-scenario-'.bin2hex(random_bytes(4));
    $mac = $plugins.'/sifrious.molly/Surfaces.bundle/Contents/MacOS';
    File::ensureDirectoryExists($mac);
    File::put($mac.'/MollySurfaces', 'molly-surfaces-binary');
    File::put($plugins.'/sifrious.molly/Surfaces.bundle/Contents/Info.plist', "<plist><string>MollySurfaceProvider</string></plist>\n");
    File::put($plugins.'/sifrious.molly/plugin.json', json_encode([
        'id' => 'sifrious.molly',
        'apiVersion' => 1,
        'version' => '0.2.0',
    ], JSON_THROW_ON_ERROR)."\n");

    return $plugins;
}

function scenarioRecorder(string $plugins, string $load): HostObservationRecorder
{
    $candidate = str_repeat('cd', 20);
    app()->instance(PackageIdentityReader::class, new PackageIdentityReader(new PackageIdentity(
        $candidate, 'test', '0.2.0', null, null, false, null, null, null,
    )));
    $listing = "4242 /Applications/Bloom.app/Contents/MacOS/Bloom\n";
    app()->instance(BloomHostInspector::class, new BloomHostInspector($listing, $plugins, null, $load));

    return new HostObservationRecorder(
        new ListMollyProjects(new ProjectRegistry(sys_get_temp_dir())),
        app(ListTasks::class),
        app(BloomHostInspector::class),
        app(PackageIdentityReader::class),
    );
}

it('stages an incompatible plugin and restores the apiVersion 1 manifest', function (): void {
    $plugins = scenarioPlugins();
    $action = new StageBloomHostScenario;
    $installed = $plugins.'/sifrious.molly/plugin.json';

    try {
        $staged = $action->stage('incompat', $plugins);
        $backup = json_decode(File::get($plugins.'/'.StageBloomHostScenario::BACKUP.'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $live = json_decode(File::get($installed), true, flags: JSON_THROW_ON_ERROR);

        expect($staged['status'])->toBe('staged')
            ->and($staged['scenario'])->toBe('incompat')
            ->and($live['apiVersion'])->toBe(2)
            ->and($backup['apiVersion'])->toBe(1)
            ->and(File::exists($plugins.'/sifrious.molly/Surfaces.bundle/Contents/MacOS/MollySurfaces'))->toBeTrue();
        expect(fn () => $action->stage('broken', $plugins))->toThrow(RuntimeException::class, 'SCENARIO_ALREADY_STAGED');

        $restored = $action->restore($plugins);
        $live = json_decode(File::get($installed), true, flags: JSON_THROW_ON_ERROR);

        expect($restored['status'])->toBe('restored')
            ->and($restored['scenario'])->toBe('incompat')
            ->and($live['apiVersion'])->toBe(1)
            ->and(File::isDirectory($plugins.'/'.StageBloomHostScenario::BACKUP))->toBeFalse()
            ->and($action->restore($plugins)['status'])->toBe('not_staged');
    } finally {
        File::deleteDirectory($plugins);
    }
});

it('removes the plugin binary for a broken scenario and puts the same bytes back', function (): void {
    $plugins = scenarioPlugins();
    $binary = $plugins.'/sifrious.molly/Surfaces.bundle/Contents/MacOS/MollySurfaces';
    $action = new StageBloomHostScenario;

    try {
        $staged = $action->stage('broken', $plugins);

        expect($staged['status'])->toBe('staged')
            ->and(File::exists($binary))->toBeFalse()
            ->and(File::get($plugins.'/'.StageBloomHostScenario::BACKUP.'/Surfaces.bundle/Contents/MacOS/MollySurfaces'))->toBe('molly-surfaces-binary');

        $action->restore($plugins);

        expect(File::get($binary))->toBe('molly-surfaces-binary')
            ->and(json_decode(File::get($plugins.'/sifrious.molly/plugin.json'), true)['apiVersion'])->toBe(1);
    } finally {
        File::deleteDirectory($plugins);
    }
});

it('refuses to stage when the plugin is not installed', function (): void {
    $plugins = sys_get_temp_dir().'/molly-scenario-empty-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($plugins);

    try {
        expect(fn () => (new StageBloomHostScenario)->stage('incompat', $plugins))
            ->toThrow(RuntimeException::class, 'PLUGIN_NOT_INSTALLED');
    } finally {
        File::deleteDirectory($plugins);
    }
});

it('records host logs from a staged scenario and leaves the install restored', function (): void {
    $plugins = scenarioPlugins();
    $project = sys_get_temp_dir().'/molly-scenario-project-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($project);
    $action = new StageBloomHostScenario;

    try {
        $action->stage('incompat', $plugins);
        $incompat = scenarioRecorder($plugins, '')->recordDiagnostics($project);
        $action->restore($plugins);

        $action->stage('broken', $plugins);
        $broken = scenarioRecorder($plugins, "4242 /Plugins/sifrious.molly/Surfaces.bundle/Contents/MacOS/MollySurfaces\n")->recordDiagnostics($project);
        $action->restore($plugins);

        $incompatLog = json_decode(File::get($project.'/host-incompat.log'), true, flags: JSON_THROW_ON_ERROR);
        $brokenLog = json_decode(File::get($project.'/host-broken.log'), true, flags: JSON_THROW_ON_ERROR);

        expect($incompat['records'][0]['check'])->toBe('M04.13')
            ->and($incompatLog['loaded'])->toBeFalse()
            ->and($incompatLog['api_version'])->toBe(2)
            ->and($incompatLog['process']['pid'])->toBe(4242)
            ->and($incompatLog['plugin'])->toBe('sifrious.molly')
            ->and($broken['records'][0]['check'])->toBe('M04.15')
            ->and($brokenLog['missing_bundle'])->toBeTrue()
            ->and($brokenLog['missing_provider'])->toBeFalse()
            ->and(File::get($plugins.'/sifrious.molly/Surfaces.bundle/Contents/MacOS/MollySurfaces'))->toBe('molly-surfaces-binary')
            ->and(json_decode(File::get($plugins.'/sifrious.molly/plugin.json'), true)['apiVersion'])->toBe(1);
    } finally {
        File::deleteDirectory($plugins);
        File::deleteDirectory($project);
    }
});

it('stages and restores through molly:host-scenario', function (): void {
    $plugins = scenarioPlugins();

    try {
        $exit = Artisan::call('molly:host-scenario', ['action' => 'stage', 'scenario' => 'incompat', '--plugins' => $plugins, '--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0)
            ->and($payload['status'])->toBe('staged')
            ->and($payload['next'][0])->toContain('Quit Bloom');

        $exit = Artisan::call('molly:host-scenario', ['action' => 'restore', '--plugins' => $plugins, '--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0)
            ->and($payload['status'])->toBe('restored')
            ->and(json_decode(File::get($plugins.'/sifrious.molly/plugin.json'), true)['apiVersion'])->toBe(1);
    } finally {
        File::deleteDirectory($plugins);
    }
});
