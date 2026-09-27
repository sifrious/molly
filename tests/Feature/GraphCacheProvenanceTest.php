<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * Two projects share one exact-version graph cache. Framework source rows must not
 * carry an absolute path from whichever project built the cache entry.
 */

beforeEach(function (): void {
    $this->mollyHome = sys_get_temp_dir().'/molly-home-'.Str::uuid();
    File::ensureDirectoryExists($this->mollyHome);
    putenv('MOLLY_HOME='.$this->mollyHome);
    $_ENV['MOLLY_HOME'] = $this->mollyHome;

    $this->projects = [];
    foreach (['a', 'b'] as $name) {
        $root = sys_get_temp_dir().'/molly-cache-'.$name.'-'.Str::uuid();
        File::ensureDirectoryExists($root);
        File::put($root.'/artisan', "#!/usr/bin/env php\n<?php\n");
        File::put($root.'/composer.json', json_encode(['require' => ['laravel/framework' => '^12.0']]));
        File::put($root.'/composer.lock', json_encode([
            'packages' => [['name' => 'laravel/framework', 'version' => 'v12.0.0']],
            'packages-dev' => [],
        ]));
        $this->projects[$name] = $root;
    }
});

afterEach(function (): void {
    foreach ($this->projects as $root) {
        File::deleteDirectory($root);
    }
    File::deleteDirectory($this->mollyHome);
    putenv('MOLLY_HOME');
    unset($_ENV['MOLLY_HOME']);
});

/** @return list<array<string, mixed>> */
function frameworkSources(string $database): array
{
    $pdo = new PDO('sqlite:'.$database);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo->query("SELECT location, metadata FROM sources WHERE type = 'framework_source' ORDER BY source_key")->fetchAll();
}

it('stores framework source locations relative to the package so a shared cache never leaks another project path', function (): void {
    $units = [];
    foreach ($this->projects as $name => $root) {
        config()->set('molly.knowledge.database', $root.'/.molly/knowledge.sqlite');
        expect(Artisan::call('molly:graphs-bootstrap', ['path' => $root, '--json' => true]))->toBe(0);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $units[$name] = collect($payload['units'])->firstWhere('id', 'laravel:laravel/framework')['source'];
    }

    expect($units)->toBe(['a' => 'built', 'b' => 'cache']);

    $a = frameworkSources($this->projects['a'].'/.molly/knowledge.sqlite');
    $b = frameworkSources($this->projects['b'].'/.molly/knowledge.sqlite');
    expect($b)->not->toBeEmpty()->and($b)->toBe($a);

    $packageRoot = dirname(__DIR__, 2);
    foreach ($b as $row) {
        $metadata = json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR);
        expect($row['location'])->toStartWith('vendor/laravel/framework/src/')
            ->and($row['location'])->not->toContain($this->projects['a'])
            ->and($metadata['package'])->toBe('laravel/framework')
            ->and('vendor/laravel/framework/'.$metadata['package_path'])->toBe($row['location'])
            ->and(is_file($packageRoot.'/'.$row['location']))->toBeTrue();
    }
});
