<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\GetMollySettings;
use Sifrious\Molly\Actions\ResolveEffectiveRunConfig;
use Sifrious\Molly\Actions\UpdateMollySettings;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Settings\MollySettings;
use Sifrious\Molly\Settings\SettingsStore;

beforeEach(function (): void {
    $this->mollyHome = sys_get_temp_dir().'/molly-settings-'.Str::uuid();
    File::ensureDirectoryExists($this->mollyHome);
    putenv('MOLLY_HOME='.$this->mollyHome);
    $_ENV['MOLLY_HOME'] = $this->mollyHome;
});

afterEach(function (): void {
    File::deleteDirectory($this->mollyHome);
    putenv('MOLLY_HOME');
    unset($_ENV['MOLLY_HOME']);
});

it('exposes documented defaults including graph storage', function (): void {
    $defaults = MollySettings::DEFAULTS;
    expect($defaults['graph_storage']['project_database'])->toBe('.molly/knowledge.sqlite')
        ->and($defaults['graph_storage']['exact_version_cache'])->toBe('~/.molly/graph-cache')
        ->and($defaults['loop']['max_iterations'])->toBe(3)
        ->and($defaults['runtime']['agent'])->toBe('ollama');

    $payload = (new GetMollySettings(new SettingsStore($this->mollyHome)))->handle();
    expect($payload['settings']['graph_storage']['project_database'])->toBe('.molly/knowledge.sqlite')
        ->and($payload['defaults'])->toBe($defaults);
});

it('persists global settings under MOLLY_HOME without touching run snapshots', function (): void {
    $store = new SettingsStore($this->mollyHome);
    $updated = (new UpdateMollySettings($store))->handle([
        'loop' => ['max_iterations' => 7],
        'runtime' => ['model' => 'llama-test'],
    ]);

    expect($updated['settings']['loop']['max_iterations'])->toBe(7)
        ->and(is_file($this->mollyHome.'/settings.json'))->toBeTrue();

    $reread = (new GetMollySettings($store))->handle();
    expect($reread['settings']['loop']['max_iterations'])->toBe(7)
        ->and($reread['settings']['runtime']['model'])->toBe('llama-test')
        ->and($reread['settings']['loop']['timeout_seconds'])->toBe(180);
});

it('freezes effective config on a run and ignores later global changes', function (): void {
    $store = new SettingsStore($this->mollyHome);
    (new UpdateMollySettings($store))->handle(['loop' => ['max_iterations' => 4]]);

    $snapshot = (new ResolveEffectiveRunConfig($store))->handle();
    expect($snapshot['config']['loop']['max_iterations'])->toBe(4);

    $run = Run::create([
        'prompt' => 'demo',
        'workspace' => base_path(),
        'status' => 'completed',
        'report' => [],
        'effective_config' => $snapshot,
    ]);

    (new UpdateMollySettings($store))->handle(['loop' => ['max_iterations' => 9]]);

    $fresh = Run::find($run->id);
    expect($fresh->effective_config['config']['loop']['max_iterations'])->toBe(4)
        ->and((new GetMollySettings($store))->handle()['settings']['loop']['max_iterations'])->toBe(9);
});

it('merges task overrides into the effective snapshot only', function (): void {
    $store = new SettingsStore($this->mollyHome);
    $snapshot = (new ResolveEffectiveRunConfig($store))->handle([
        'loop' => ['max_iterations' => 2],
    ]);

    expect($snapshot['config']['loop']['max_iterations'])->toBe(2)
        ->and($snapshot['config']['graph_storage']['project_database'])->toBe('.molly/knowledge.sqlite');
});
