<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\BootstrapProjectKnowledgeGraphs;
use Sifrious\Molly\Actions\CreateMollyProject;
use Sifrious\Molly\Actions\InitializeMollyInExistingProject;
use Sifrious\Molly\Actions\ListMollyProjects;
use Sifrious\Molly\Projects\ProjectRegistry;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->mollyHome = sys_get_temp_dir().'/molly-home-'.Str::uuid();
    File::ensureDirectoryExists($this->mollyHome);
    putenv('MOLLY_HOME='.$this->mollyHome);
    $_ENV['MOLLY_HOME'] = $this->mollyHome;
    $this->registry = new ProjectRegistry($this->mollyHome);

    $this->laravelRoot = sys_get_temp_dir().'/molly-laravel-'.Str::uuid();
    File::ensureDirectoryExists($this->laravelRoot);
    File::put($this->laravelRoot.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($this->laravelRoot.'/composer.json', json_encode([
        'name' => 'example/app',
        'require' => ['laravel/framework' => '^12.0'],
        'require-dev' => [],
    ], JSON_PRETTY_PRINT));
    File::put($this->laravelRoot.'/.gitignore', "/vendor\n");
    File::put($this->laravelRoot.'/.env', "APP_NAME=Example\nAPP_KEY=base64:dGVzdA==\nMOLLY_AGENT=keep-me\n");
    File::ensureDirectoryExists($this->laravelRoot.'/config');
    File::ensureDirectoryExists($this->laravelRoot.'/vendor/sifrious/molly');
    commitGitWorkspace($this->laravelRoot);
});

afterEach(function (): void {
    File::deleteDirectory($this->mollyHome);
    File::deleteDirectory($this->laravelRoot);
    putenv('MOLLY_HOME');
    unset($_ENV['MOLLY_HOME']);
});

it('initializes an existing Laravel app without overwriting env or existing molly config', function (): void {
    File::put($this->laravelRoot.'/config/molly.php', "<?php\n\nreturn ['agent' => 'custom'];\n");

    $result = (new InitializeMollyInExistingProject($this->registry, app(BootstrapProjectKnowledgeGraphs::class)))->handle(
        path: $this->laravelRoot,
        name: 'Example App',
        runComposerRequire: false,
        runMigrations: false,
        bootstrapGraphs: false,
    );

    expect($result['project']->name)->toBe('Example App')
        ->and($result['project']->source)->toBe('existing')
        ->and($result['created']['metadata'])->toBeTrue()
        ->and($result['created']['config'])->toBeFalse()
        ->and(File::get($this->laravelRoot.'/config/molly.php'))->toContain("'agent' => 'custom'")
        ->and(File::get($this->laravelRoot.'/.env'))->toContain('MOLLY_AGENT=keep-me')
        ->and(File::get($this->laravelRoot.'/.gitignore'))->toContain('.molly/')
        ->and(File::exists($this->laravelRoot.'/.molly/project.json'))->toBeTrue()
        ->and(File::exists($this->mollyHome.'/projects.json'))->toBeTrue();

    $listed = (new ListMollyProjects($this->registry))->handle();
    expect($listed)->toHaveCount(1)
        ->and($listed[0]->path)->toBe(str_replace('\\', '/', realpath($this->laravelRoot)));
});

it('refuses a non-laravel directory', function (): void {
    $empty = sys_get_temp_dir().'/molly-not-laravel-'.Str::uuid();
    File::ensureDirectoryExists($empty);

    expect(fn () => (new InitializeMollyInExistingProject($this->registry, app(BootstrapProjectKnowledgeGraphs::class)))->handle(
        path: $empty,
        runComposerRequire: false,
        runMigrations: false,
        bootstrapGraphs: false,
    ))->toThrow(RuntimeException::class, 'PROJECT_NOT_LARAVEL');

    File::deleteDirectory($empty);
});

it('creates a new project scaffold and marks source as new', function (): void {
    // Inside a committed repository, so Molly can attach the new app right away.
    $parent = sys_get_temp_dir().'/molly-new-'.Str::uuid();
    File::ensureDirectoryExists($parent);
    commitGitWorkspace($parent);
    $target = $parent.'/app';

    $result = (new CreateMollyProject(
        new InitializeMollyInExistingProject($this->registry, app(BootstrapProjectKnowledgeGraphs::class)),
        $this->registry,
    ))->handle(
        path: $target,
        name: 'Fresh',
        runComposer: false,
        runMigrations: false,
        bootstrapGraphs: false,
    );

    expect($result['project']->source)->toBe('new')
        ->and($result['project']->name)->toBe('Fresh')
        ->and(File::exists($target.'/.molly/project.json'))->toBeTrue()
        ->and(json_decode(File::get($this->mollyHome.'/projects.json'), true))->toContain(str_replace('\\', '/', realpath($target)));

    File::deleteDirectory($parent);
});

it('exposes init and list over artisan with shared registry state', function (): void {
    $exit = Artisan::call('molly:project-init', [
        '--no-graphs' => true,
        'path' => $this->laravelRoot,
        '--name' => 'Via CLI',
        '--no-composer' => true,
        '--no-migrate' => true,
        '--json' => true,
    ]);
    $init = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)
        ->and($init['status'])->toBe('initialized')
        ->and($init['project']['name'])->toBe('Via CLI');

    $exit = Artisan::call('molly:projects', ['--json' => true]);
    $list = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)
        ->and($list['projects'])->toHaveCount(1)
        ->and($list['projects'][0]['name'])->toBe('Via CLI');
});

it('refuses molly:project-init outside a committed repository before writing anything', function (bool $initialize, string $code): void {
    $root = sys_get_temp_dir().'/molly-laravel-nogit-'.Str::uuid();
    File::ensureDirectoryExists($root);
    File::put($root.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($root.'/composer.json', json_encode(['name' => 'example/app', 'require' => ['laravel/framework' => '^12.0']]));
    if ($initialize) {
        (new Process(['git', 'init', '--quiet', $root]))->mustRun();
    }
    $before = File::allFiles($root, true);

    try {
        $exit = Artisan::call('molly:project-init', ['path' => $root, '--no-composer' => true, '--no-graphs' => true, '--no-migrate' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($payload['error'])->toStartWith($code.': ')
            ->and(File::exists($root.'/.molly'))->toBeFalse()
            ->and(File::exists($root.'/.gitignore'))->toBeFalse()
            ->and(File::allFiles($root, true))->toEqual($before)
            ->and(File::exists($this->mollyHome.'/projects.json'))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
})->with([
    'no repository' => [false, 'WORKSPACE_NOT_GIT'],
    'no commit' => [true, 'WORKSPACE_REVISION_MISSING'],
]);

it('creates the app with molly:project-new outside a repository and asks for a commit before attaching Molly', function (): void {
    $target = sys_get_temp_dir().'/molly-new-'.Str::uuid();

    try {
        $exit = Artisan::call('molly:project-new', ['path' => $target, '--no-composer' => true, '--no-graphs' => true, '--no-migrate' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0)
            ->and($payload['status'])->toBe('needs_commit')
            ->and($payload['project'])->toBeNull()
            ->and($payload['next'])->toBe([
                'git -C '.escapeshellarg($target).' init',
                'git -C '.escapeshellarg($target).' add -A',
                'git -C '.escapeshellarg($target).' commit -m "Start"',
                'php artisan molly:project-init '.escapeshellarg($target),
            ])
            ->and(File::exists($target.'/artisan'))->toBeTrue()
            ->and(File::exists($target.'/.git'))->toBeFalse()
            ->and(File::exists($target.'/.molly'))->toBeFalse()
            ->and(File::exists($this->mollyHome.'/projects.json'))->toBeFalse();

        commitGitWorkspace($target);
        $exit = Artisan::call('molly:project-init', ['path' => $target, '--no-composer' => true, '--no-graphs' => true, '--no-migrate' => true, '--json' => true]);

        expect($exit)->toBe(0)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status'])->toBe('initialized');
    } finally {
        File::deleteDirectory($target);
    }
});

it('tells the user to commit when molly:project-new stops before attaching Molly', function (): void {
    $target = sys_get_temp_dir().'/molly-new-'.Str::uuid();

    try {
        $this->artisan('molly:project-new', ['path' => $target, '--no-composer' => true, '--no-graphs' => true, '--no-migrate' => true])
            ->expectsOutputToContain('Molly has not attached it yet')
            ->expectsOutputToContain('git -C '.escapeshellarg($target).' commit -m "Start"')
            ->assertSuccessful();
    } finally {
        File::deleteDirectory($target);
    }
});
