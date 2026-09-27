<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * These tests go through Artisan and the container binding for
 * BootstrapProjectKnowledgeGraphs. Nothing here injects a hand-built bootstrap.
 */

beforeEach(function (): void {
    $this->mollyHome = sys_get_temp_dir().'/molly-home-'.Str::uuid();
    File::ensureDirectoryExists($this->mollyHome);
    putenv('MOLLY_HOME='.$this->mollyHome);
    $_ENV['MOLLY_HOME'] = $this->mollyHome;

    $this->knowledgeDatabase = sys_get_temp_dir().'/molly-knowledge-'.Str::uuid().'.sqlite';
    config()->set('molly.knowledge.database', $this->knowledgeDatabase);

    $this->laravelRoot = sys_get_temp_dir().'/molly-init-'.Str::uuid();
    File::ensureDirectoryExists($this->laravelRoot.'/vendor/sifrious/molly');
    File::put($this->laravelRoot.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($this->laravelRoot.'/composer.json', json_encode([
        'name' => 'example/app',
        'require' => ['laravel/framework' => '^12.0'],
    ], JSON_PRETTY_PRINT));
});

afterEach(function (): void {
    File::deleteDirectory($this->mollyHome);
    File::deleteDirectory($this->laravelRoot);
    File::delete($this->knowledgeDatabase);
    putenv('MOLLY_HOME');
    unset($_ENV['MOLLY_HOME']);
});

function writeInitLock(string $root, array $packages): void
{
    File::put($root.'/composer.lock', json_encode(['packages' => $packages, 'packages-dev' => []], JSON_PRETTY_PRINT));
}

function registeredPaths(string $home): array
{
    $index = $home.'/projects.json';

    return is_file($index) ? (json_decode(File::get($index), true) ?: []) : [];
}

it('runs molly:project-init with the container bootstrap and registers the project after the graphs are built', function (): void {
    writeInitLock($this->laravelRoot, [['name' => 'laravel/framework', 'version' => 'v12.0.0']]);

    $exit = Artisan::call('molly:project-init', [
        'path' => $this->laravelRoot,
        '--no-composer' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $root = str_replace('\\', '/', realpath($this->laravelRoot));
    expect($exit)->toBe(0)
        ->and($payload['status'])->toBe('initialized')
        ->and($payload['graphs']['ok'])->toBeTrue()
        ->and($payload['graphs']['laravel_exact'])->toBe('12.0.0')
        ->and(File::exists($this->laravelRoot.'/.molly/graphs/manifest.json'))->toBeTrue()
        ->and(File::exists($this->laravelRoot.'/.molly/project.json'))->toBeTrue()
        ->and(registeredPaths($this->mollyHome))->toContain($root);

    $steps = $payload['steps'];
    $graphsDone = array_key_last(array_filter($steps, fn (string $step): bool => str_starts_with($step, 'graphs')));
    $registered = array_key_first(array_filter($steps, fn (string $step): bool => str_starts_with($step, 'register')));
    expect($registered)->toBeGreaterThan($graphsDone);
});

it('does not register a project when molly:project-init fails during the graph bootstrap', function (): void {
    writeInitLock($this->laravelRoot, [['name' => 'some/other', 'version' => '1.0.0']]);

    $exit = Artisan::call('molly:project-init', [
        'path' => $this->laravelRoot,
        '--no-composer' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['status'])->toBe('error')
        ->and($payload['error'])->toContain('LARAVEL_VERSION_MISSING')
        ->and(File::exists($this->laravelRoot.'/.molly/project.json'))->toBeFalse()
        ->and(registeredPaths($this->mollyHome))->toBe([]);
});

it('runs molly:project-new with the container bootstrap', function (): void {
    $target = sys_get_temp_dir().'/molly-new-'.Str::uuid();

    try {
        $exit = Artisan::call('molly:project-new', [
            'path' => $target,
            '--no-composer' => true,
            '--no-migrate' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0)
            ->and($payload['status'])->toBe('created')
            ->and($payload['project']['source'])->toBe('new')
            ->and(File::exists($target.'/.molly/graphs/manifest.json'))->toBeTrue()
            ->and(registeredPaths($this->mollyHome))->toContain(str_replace('\\', '/', realpath($target)));
    } finally {
        File::deleteDirectory($target);
    }
});
