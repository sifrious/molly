<?php

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->mollyHome = sys_get_temp_dir().'/molly-home-'.Str::uuid();
    File::ensureDirectoryExists($this->mollyHome);
    putenv('MOLLY_HOME='.$this->mollyHome);
    $_ENV['MOLLY_HOME'] = $this->mollyHome;

    $this->project = sys_get_temp_dir().'/molly-fresh-'.Str::uuid();
    File::ensureDirectoryExists($this->project);
    File::put($this->project.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($this->project.'/composer.json', json_encode(['require' => ['laravel/framework' => '*']]));
    $this->laravel = ltrim((string) InstalledVersions::getPrettyVersion('laravel/framework'), 'v');
    writeFreshnessLock($this->project, $this->laravel);
    config()->set('molly.knowledge.database', $this->project.'/.molly/knowledge.sqlite');
});

afterEach(function (): void {
    File::deleteDirectory($this->project);
    File::deleteDirectory($this->mollyHome);
    putenv('MOLLY_HOME');
    unset($_ENV['MOLLY_HOME']);
});

function writeFreshnessLock(string $root, string $laravel): void
{
    File::put($root.'/composer.lock', json_encode([
        'packages' => [['name' => 'laravel/framework', 'version' => 'v'.$laravel]],
        'packages-dev' => [],
    ], JSON_PRETTY_PRINT));
}

function gitIn(string $root, array $arguments): string
{
    return trim(Process::path($root)->run(['git', ...$arguments])->throw()->output());
}

/** @return array{0: int, 1: array<string, mixed>} */
function artisanJson(string $command, array $arguments): array
{
    $exit = Artisan::call($command, [...$arguments, '--json' => true]);

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

function commitAll(string $root, string $message): void
{
    gitIn($root, ['add', '-A']);
    gitIn($root, ['-c', 'user.name=Molly Test', '-c', 'user.email=molly@example.test', 'commit', '-qm', $message]);
}

it('records the Git revision and lock digest in the manifest and reports fresh graphs', function (): void {
    gitIn($this->project, ['init', '-q']);
    commitAll($this->project, 'Start');
    $head = gitIn($this->project, ['rev-parse', 'HEAD']);

    [$exit] = artisanJson('molly:graphs-bootstrap', ['path' => $this->project]);
    $manifest = json_decode(File::get($this->project.'/.molly/graphs/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($manifest['revision'])->toBe($head);
    foreach ($manifest['units'] as $unit) {
        expect($unit['revision'])->toBe($head)
            ->and($unit['lock_hash'])->toBe(hash_file('sha256', $this->project.'/composer.lock'));
    }

    [$exit, $knowledge] = artisanJson('molly:knowledge:query', ['concept' => 'Queue', '--workspace' => $this->project]);
    expect($exit)->toBe(0)
        ->and($knowledge['nodes'])->not->toBeEmpty()
        ->and($knowledge['freshness']['stale'])->toBeFalse()
        ->and($knowledge['freshness']['status'])->toBe('fresh');

    [$exit, $project] = artisanJson('molly:project:query', ['concept' => 'Workspace', '--workspace' => $this->project]);
    expect($exit)->toBe(0)->and($project['freshness']['stale'])->toBeFalse();

    [$exit, $status] = artisanJson('molly:status', ['--workspace' => $this->project]);
    expect($exit)->toBe(0)
        ->and($status['graphs']['status'])->toBe('fresh')
        ->and($status['graphs']['stale'])->toBeFalse();
});

it('reports revision_changed after a commit lands on top of the bootstrapped graph', function (): void {
    gitIn($this->project, ['init', '-q']);
    commitAll($this->project, 'Start');
    artisanJson('molly:graphs-bootstrap', ['path' => $this->project]);

    File::put($this->project.'/Greeter.php', "<?php\n");
    commitAll($this->project, 'Add Greeter after bootstrap');

    [$exit, $knowledge] = artisanJson('molly:knowledge:query', ['concept' => 'Queue', '--workspace' => $this->project]);
    expect($exit)->toBe(0)
        ->and($knowledge['freshness']['stale'])->toBeTrue()
        ->and($knowledge['freshness']['reasons'])->toBe(['revision_changed'])
        ->and($knowledge['freshness']['fix'])->toBe('php artisan molly:graphs-bootstrap');

    [, $project] = artisanJson('molly:project:query', ['concept' => 'Workspace', '--workspace' => $this->project]);
    expect($project['freshness']['stale'])->toBeTrue()
        ->and($project['freshness']['reasons'])->toBe(['revision_changed']);

    [, $status] = artisanJson('molly:status', ['--workspace' => $this->project]);
    expect($status['graphs']['stale'])->toBeTrue()
        ->and($status['graphs']['reasons'])->toBe(['revision_changed']);

    artisanJson('molly:graphs-bootstrap', ['path' => $this->project]);
    [, $again] = artisanJson('molly:knowledge:query', ['concept' => 'Queue', '--workspace' => $this->project]);
    expect($again['freshness']['stale'])->toBeFalse();
});

it('refuses to answer from a Laravel graph built for a different exact version', function (): void {
    artisanJson('molly:graphs-bootstrap', ['path' => $this->project]);
    writeFreshnessLock($this->project, $this->laravel.'-changed');

    [$exit, $knowledge] = artisanJson('molly:knowledge:query', ['concept' => 'Queue', '--workspace' => $this->project]);
    expect($exit)->toBe(1)
        ->and($knowledge['status'])->toBe('error')
        ->and($knowledge['error'])->toStartWith('GRAPH_STALE:')
        ->and($knowledge['error'])->toContain($this->laravel.'-changed')
        ->and($knowledge['error'])->toContain('php artisan molly:graphs-bootstrap');

    [$exit, $project] = artisanJson('molly:project:query', ['concept' => 'Workspace', '--workspace' => $this->project]);
    expect($exit)->toBe(0)
        ->and($project['freshness']['reasons'])->toBe(['lock_changed']);

    [, $status] = artisanJson('molly:status', ['--workspace' => $this->project]);
    expect($status['graphs']['reasons'])->toBe(['lock_changed', 'version_changed']);
});

it('records no-git for a project without a Git checkout and does not create one', function (): void {
    [$exit] = artisanJson('molly:graphs-bootstrap', ['path' => $this->project]);
    $manifest = json_decode(File::get($this->project.'/.molly/graphs/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

    [, $status] = artisanJson('molly:status', ['--workspace' => $this->project]);
    expect($exit)->toBe(0)
        ->and($manifest['revision'])->toBe('no-git')
        ->and($status['graphs']['stale'])->toBeFalse()
        ->and(file_exists($this->project.'/.git'))->toBeFalse();
});
