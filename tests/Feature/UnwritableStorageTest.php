<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CheckEnvironment;

/*
 * A full disk and a read-only directory fail the same system calls (tempnam, mkdir, and
 * SQLite writes), so these tests use read-only paths to stand in for a full volume.
 */

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/molly-unwritable-'.Str::uuid();
    File::ensureDirectoryExists($this->directory);
    $this->directory = realpath($this->directory);
});

afterEach(function (): void {
    exec('chmod -R u+w '.escapeshellarg($this->directory));
    File::deleteDirectory($this->directory);
});

it('fails molly:setup with ENV_UNWRITABLE naming the directory when no temporary file fits beside .env', function (): void {
    File::put($this->directory.'/.env', "APP_NAME=Example\n");
    chmod($this->directory, 0555);
    $this->app->useEnvironmentPath($this->directory);
    config(['ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://localhost:11434']]);
    Http::fake(['localhost:11434/api/tags' => Http::response(['models' => [['name' => 'qwen:7b']]])]);

    $exit = Artisan::call('molly:setup', ['--agent' => 'ollama', '--model' => 'qwen:7b', '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($result['error'])->toStartWith('ENV_UNWRITABLE: Molly could not update '.$this->directory.'/.env')
        ->and($result['error'])->toContain('temporary file in '.$this->directory)
        ->and(File::get($this->directory.'/.env'))->toBe("APP_NAME=Example\n");
});

it('fails molly:setup with ENV_UNWRITABLE when .env is read-only', function (): void {
    File::put($this->directory.'/.env', "APP_NAME=Example\n");
    chmod($this->directory.'/.env', 0444);
    $this->app->useEnvironmentPath($this->directory);
    config(['ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://localhost:11434']]);
    Http::fake(['localhost:11434/api/tags' => Http::response(['models' => [['name' => 'qwen:7b']]])]);

    $exit = Artisan::call('molly:setup', ['--agent' => 'ollama', '--model' => 'qwen:7b', '--json' => true]);

    expect($exit)->toBe(1)
        ->and(json_decode(Artisan::output(), true)['error'])->toContain('ENV_UNWRITABLE', 'read-only')
        ->and(File::get($this->directory.'/.env'))->toBe("APP_NAME=Example\n");
});

it('names the directory molly:worker start could not create', function (): void {
    File::ensureDirectoryExists($this->directory.'/.molly');
    chmod($this->directory.'/.molly', 0555);

    $exit = Artisan::call('molly:worker', ['action' => 'start', '--workspace' => $this->directory, '--json' => true]);
    $error = json_decode(Artisan::output(), true)['error'];

    expect($exit)->toBe(1)
        ->and($error)->toStartWith('DIRECTORY_UNWRITABLE: Molly could not create '.$this->directory.'/.molly/worker')
        ->and($error)->not->toContain('unknown error');
});

it('names the directory molly:demo could not create', function (): void {
    File::put($this->directory.'/.gitignore', ".molly/\n");
    chmod($this->directory, 0555);

    $exit = Artisan::call('molly:demo', ['--workspace' => $this->directory, '--json' => true]);

    expect($exit)->toBe(1)
        ->and(json_decode(Artisan::output(), true)['error'])->toStartWith('DIRECTORY_UNWRITABLE: Molly could not create '.$this->directory.'/app');
});

it('reports database_unwritable when the database cannot accept a write', function (): void {
    $database = $this->directory.'/database.sqlite';
    touch($database);
    config(['database.connections.probe' => ['driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true]]);
    Artisan::call('migrate', ['--database' => 'probe', '--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true]);
    $default = config('database.default');
    config(['database.default' => 'probe']);

    try {
        $ready = array_column(app(CheckEnvironment::class)->handle($this->directory)['checks'], null, 'name')['Run history'];
        $leftover = DB::table('molly_plans')->count();

        DB::purge('probe');
        chmod($database, 0444);
        chmod($this->directory, 0555);
        $check = array_column(app(CheckEnvironment::class)->handle($this->directory)['checks'], null, 'name')['Run history'];
    } finally {
        config(['database.default' => $default]);
        DB::purge('probe');
    }

    expect($ready['code'])->toBe('database_ready')
        ->and($leftover)->toBe(0)
        ->and($check['status'])->toBe('failed')
        ->and($check['code'])->toBe('database_unwritable')
        ->and($check['message'])->toContain('free disk space');
});
