<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CheckEnvironment;
use Sifrious\Molly\Workspace\Directory;

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
    commitGitWorkspace($this->directory);
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

it('names the path and the reason when Directory::ensure cannot create a directory', function (): void {
    chmod($this->directory, 0555);

    expect(fn () => Directory::ensure($this->directory.'/nested/deeper'))
        ->toThrow(RuntimeException::class, 'DIRECTORY_UNWRITABLE: Molly could not create '.$this->directory.'/nested/deeper (Permission denied).');
});

/** @return array{exit: int, error: string} */
function initProject(string $root, string $home): array
{
    putenv('MOLLY_HOME='.$home);
    try {
        $exit = Artisan::call('molly:project-init', ['path' => $root, '--no-composer' => true, '--no-graphs' => true, '--no-migrate' => true, '--json' => true]);
    } finally {
        putenv('MOLLY_HOME');
    }

    return ['exit' => $exit, 'error' => json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'] ?? ''];
}

function laravelRoot(string $root): string
{
    File::ensureDirectoryExists($root.'/config');
    File::put($root.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($root.'/composer.json', '{"name":"example/app","require":{"laravel/framework":"^13.0"}}');
    File::put($root.'/.gitignore', ".molly/\n");

    return $root;
}

it('fails molly:project-init with a code naming the .molly file it could not write when .molly is read-only', function (bool $identified, string $code, string $file): void {
    $root = laravelRoot($this->directory.'/app');
    File::put($root.'/config/molly.php', "<?php\n\nreturn [];\n");
    File::ensureDirectoryExists($root.'/.molly');
    if ($identified) {
        File::put($root.'/.molly/identity.json', json_encode(array_fill_keys(['project_id', 'workspace_id', 'repository_id', 'checkout_id'], (string) Str::uuid())));
    }
    chmod($root.'/.molly', 0555);

    $result = initProject($root, $this->directory.'/home');

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toBe($code.': Molly could not write '.$root.'/.molly/'.$file.' (Permission denied). Check free disk space and that the directory is writable.');
})->with([
    'new checkout' => [false, 'WORKSPACE_IDENTITY_UNWRITABLE', 'identity.json'],
    'identified checkout' => [true, 'PROJECT_RECORD_UNWRITABLE', 'project.json'],
]);

it('fails molly:project-init with CONFIG_UNWRITABLE naming config/molly.php when config is read-only', function (): void {
    $root = laravelRoot($this->directory.'/app');
    chmod($root.'/config', 0555);

    $result = initProject($root, $this->directory.'/home');

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('CONFIG_UNWRITABLE: Molly could not write '.$root.'/config/molly.php (Permission denied).')
        ->and(File::exists($root.'/.molly/project.json'))->toBeFalse();
});

it('fails molly:project-init with PROJECT_INDEX_UNWRITABLE naming projects.json when MOLLY_HOME is read-only', function (): void {
    $root = laravelRoot($this->directory.'/app');
    File::put($root.'/config/molly.php', "<?php\n\nreturn [];\n");
    File::ensureDirectoryExists($this->directory.'/home');
    chmod($this->directory.'/home', 0555);

    $result = initProject($root, $this->directory.'/home');

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('PROJECT_INDEX_UNWRITABLE: Molly could not write '.$this->directory.'/home/projects.json (Permission denied).');
});

it('fails molly:create with DATABASE_UNWRITABLE naming the database when it is read-only', function (): void {
    $workspace = $this->directory.'/app';
    File::ensureDirectoryExists($workspace.'/app');
    writeProtectedTest($workspace, 'tests/Feature/GreetingTest.php');
    commitGitWorkspace($workspace);
    $database = $this->directory.'/database.sqlite';
    touch($database);
    config(['database.connections.readonly' => ['driver' => 'sqlite', 'database' => $database, 'foreign_key_constraints' => true]]);
    Artisan::call('migrate', ['--database' => 'readonly', '--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true]);
    DB::purge('readonly');
    chmod($database, 0444);
    $default = config('database.default');
    config(['database.default' => 'readonly']);

    try {
        $exit = Artisan::call('molly:create', ['prompt' => 'Return Hi', '--workspace' => $workspace, '--file' => ['app/Greeting.php'], '--test' => 'tests/Feature/GreetingTest.php', '--json' => true]);
        $error = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'];
    } finally {
        config(['database.default' => $default]);
        DB::purge('readonly');
    }

    expect($exit)->toBe(1)
        ->and($error)->toStartWith('DATABASE_UNWRITABLE: Molly could not save the task in '.$database.' (')
        ->and($error)->toContain('readonly database')
        ->and($error)->not->toContain('SQL: insert');
});

it('fails molly:journal --project with the file, the reason, and a coded stderr line when .molly is read-only', function (): void {
    $workspace = $this->directory.'/app';
    File::ensureDirectoryExists($workspace.'/.molly');
    File::put($workspace.'/.molly/.gitignore', "*\n");
    $database = $this->directory.'/database.sqlite';
    touch($database);
    $environment = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database];
    testbenchProcess(['migrate', '--force'], $environment)->mustRun();
    chmod($workspace.'/.molly', 0555);

    $process = testbenchProcess(['molly:journal', '--project', '--workspace='.$workspace, '--json', '--no-interaction'], $environment);
    $process->run();
    $document = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe(1)
        ->and(array_keys($document))->toBe(['task_id', 'status', 'reason', 'checked_at'])
        ->and($document['status'])->toBe('unavailable')
        ->and($document['reason'])->toStartWith('JOURNAL_WRITE_FAILED: Molly could not write '.$workspace.'/.molly/GLOSSARY.md (Permission denied).')
        ->and(trim($process->getErrorOutput()))->toBe($document['reason']);
});

it('fails molly:graphs-bootstrap with DIRECTORY_UNWRITABLE naming the graphs directory when .molly is read-only', function (): void {
    $root = laravelRoot($this->directory.'/app');
    File::put($root.'/composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v13.0.0']], 'packages-dev' => []]));
    File::ensureDirectoryExists($root.'/.molly');
    chmod($root.'/.molly', 0555);
    config(['molly.knowledge.database' => $this->directory.'/knowledge.sqlite']);
    putenv('MOLLY_HOME='.$this->directory.'/home');

    try {
        $exit = Artisan::call('molly:graphs-bootstrap', ['path' => $root, '--json' => true]);
    } finally {
        putenv('MOLLY_HOME');
    }
    $error = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'];

    expect($exit)->toBe(1)
        ->and($error)->toStartWith('DIRECTORY_UNWRITABLE: Molly could not create '.$root.'/.molly/graphs (Permission denied).')
        ->and($error)->not->toContain('KNOWLEDGE_MANIFEST_INVALID');
});

it('fails graph indexing with DATABASE_UNWRITABLE naming the knowledge database when its directory is read-only', function (array $command): void {
    File::ensureDirectoryExists($this->directory.'/store');
    chmod($this->directory.'/store', 0555);
    config(['molly.knowledge.database' => $this->directory.'/store/knowledge.sqlite']);

    $exit = Artisan::call($command[0], [...$command[1], '--json' => true]);
    $error = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'];

    expect($exit)->toBe(1)
        ->and($error)->toStartWith('DATABASE_UNWRITABLE: Molly could not open the knowledge database '.$this->directory.'/store/knowledge.sqlite (')
        ->and($error)->not->toContain('SQLSTATE');
})->with([
    'project index' => [['molly:project:index', []]],
    'laravel knowledge index' => [['molly:knowledge:index', ['namespace' => 'laravel']]],
]);

it('fails graph indexing with DATABASE_UNWRITABLE when the knowledge database file is read-only', function (): void {
    $database = $this->directory.'/knowledge.sqlite';
    touch($database);
    chmod($database, 0444);
    config(['molly.knowledge.database' => $database]);

    $exit = Artisan::call('molly:project:index', ['--json' => true]);

    expect($exit)->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'])
        ->toBe('DATABASE_UNWRITABLE: Molly could not open the knowledge database '.$database.' (the file is read-only). Check free disk space and that '.$this->directory.' is writable.');
});

it('fails molly:settings-set with SETTINGS_UNWRITABLE naming settings.json when MOLLY_HOME is read-only', function (): void {
    $home = $this->directory.'/home';
    File::ensureDirectoryExists($home);
    chmod($home, 0555);
    putenv('MOLLY_HOME='.$home);

    try {
        $exit = Artisan::call('molly:settings-set', ['--patch' => '{"loop":{"max_iterations":2}}', '--json' => true]);
    } finally {
        putenv('MOLLY_HOME');
    }
    $error = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'];

    expect($exit)->toBe(1)
        ->and($error)->toBe('SETTINGS_UNWRITABLE: Molly could not write '.$home.'/settings.json (Permission denied). Check free disk space and that '.$home.' is writable.')
        ->and(File::exists($home.'/settings.json'))->toBeFalse()
        ->and(File::files($home))->toBe([]);
});

it('keeps settings.json unchanged when the file is read-only', function (): void {
    $home = $this->directory.'/home';
    File::ensureDirectoryExists($home);
    File::put($home.'/settings.json', "{}\n");
    chmod($home.'/settings.json', 0444);
    putenv('MOLLY_HOME='.$home);

    try {
        $exit = Artisan::call('molly:settings-set', ['--patch' => '{"loop":{"max_iterations":2}}', '--json' => true]);
    } finally {
        putenv('MOLLY_HOME');
    }

    expect($exit)->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'])->toStartWith('SETTINGS_UNWRITABLE: Molly could not write '.$home.'/settings.json (the file is read-only).')
        ->and(File::get($home.'/settings.json'))->toBe("{}\n");
});
