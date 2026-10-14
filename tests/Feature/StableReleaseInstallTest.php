<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\BootstrapProjectKnowledgeGraphs;
use Sifrious\Molly\Actions\InitializeMollyInExistingProject;
use Sifrious\Molly\Projects\ProjectRegistry;

beforeEach(function (): void {
    $this->mollyHome = sys_get_temp_dir().'/molly-home-'.Str::uuid();
    File::ensureDirectoryExists($this->mollyHome);
    $this->registry = new ProjectRegistry($this->mollyHome);

    $this->laravelRoot = sys_get_temp_dir().'/molly-laravel-'.Str::uuid();
    File::ensureDirectoryExists($this->laravelRoot.'/config');
    File::put($this->laravelRoot.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($this->laravelRoot.'/.gitignore', "/vendor\n");
    commitGitWorkspace($this->laravelRoot);
});

afterEach(function (): void {
    File::deleteDirectory($this->mollyHome);
    File::deleteDirectory($this->laravelRoot);
});

function writeComposerJson(string $root, array $extra = []): void
{
    File::put($root.'/composer.json', json_encode([
        'name' => 'example/app',
        'require' => ['laravel/framework' => '^12.0'],
    ] + $extra, JSON_PRETTY_PRINT));
}

function initializeWithComposer(ProjectRegistry $registry, string $root): void
{
    (new InitializeMollyInExistingProject($registry, app(BootstrapProjectKnowledgeGraphs::class)))->handle(
        path: $root,
        runComposerRequire: true,
        runMigrations: false,
        bootstrapGraphs: false,
    );
}

it('pins the initializer to a tagged release line', function (): void {
    expect(InitializeMollyInExistingProject::RELEASE_CONSTRAINT)
        ->toMatch('/^\^\d+\.\d+(\.\d+)?$/')
        ->not->toContain('dev');
});

it('requires the tagged release and adds the public repository when it is missing', function (): void {
    writeComposerJson($this->laravelRoot);
    Process::fake();

    initializeWithComposer($this->registry, $this->laravelRoot);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
        'composer', 'config', 'repositories.molly', 'vcs', InitializeMollyInExistingProject::REPOSITORY_URL,
    ]);
    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
        'composer', 'require', '--dev', 'sifrious/molly:'.InitializeMollyInExistingProject::RELEASE_CONSTRAINT.'@stable', '--no-interaction',
    ]);
    Process::assertDidntRun(fn (PendingProcess $process): bool => str_contains(implode(' ', (array) $process->command), 'dev-main'));
});

it('leaves an existing Molly repository entry alone', function (): void {
    writeComposerJson($this->laravelRoot, ['repositories' => ['molly' => ['type' => 'vcs', 'url' => 'https://github.com/sifrious/molly']]]);
    Process::fake();

    initializeWithComposer($this->registry, $this->laravelRoot);

    Process::assertDidntRun(fn (PendingProcess $process): bool => ($process->command[1] ?? null) === 'config');
    Process::assertRanTimes(fn (PendingProcess $process): bool => ($process->command[1] ?? null) === 'require', 2);
});

it('reports a failed Composer require without continuing', function (): void {
    writeComposerJson($this->laravelRoot);
    Process::fake(['*' => Process::result(errorOutput: 'Could not find a version', exitCode: 1)]);

    expect(fn () => initializeWithComposer($this->registry, $this->laravelRoot))
        ->toThrow(RuntimeException::class, 'COMPOSER_REQUIRE_FAILED');
});

it('restores composer.json and composer.lock byte for byte after a failed Composer require', function (bool $withLock): void {
    writeComposerJson($this->laravelRoot);
    File::append($this->laravelRoot.'/composer.json', "\n");
    if ($withLock) {
        File::put($this->laravelRoot.'/composer.lock', "{\n    \"packages\": []\n}\n");
    }
    $json = File::get($this->laravelRoot.'/composer.json');
    $root = $this->laravelRoot;
    // Behave like Composer: config writes the repository entry, then require touches the lock and fails.
    Process::fake(function (PendingProcess $process) use ($root) {
        if (in_array('--dry-run', (array) $process->command, true)) {
            return Process::result();
        }
        if (($process->command[1] ?? null) === 'config') {
            $composer = json_decode(File::get($root.'/composer.json'), true);
            $composer['repositories'] = ['molly' => ['type' => 'vcs', 'url' => InitializeMollyInExistingProject::REPOSITORY_URL]];
            File::put($root.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

            return Process::result();
        }
        File::put($root.'/composer.lock', '{"packages": ["partial"]}');

        return Process::result(errorOutput: 'Could not authenticate against github.com', exitCode: 1);
    });

    expect(fn () => initializeWithComposer($this->registry, $this->laravelRoot))
        ->toThrow(RuntimeException::class, 'COMPOSER_REQUIRE_FAILED: Could not authenticate against github.com');

    expect(File::get($this->laravelRoot.'/composer.json'))->toBe($json)
        ->and(File::get($this->laravelRoot.'/composer.json'))->not->toContain('repositories')
        ->and(is_file($this->laravelRoot.'/composer.lock'))->toBe($withLock)
        ->and(file_exists($this->laravelRoot.'/.molly'))->toBeFalse();
    if ($withLock) {
        expect(File::get($this->laravelRoot.'/composer.lock'))->toBe("{\n    \"packages\": []\n}\n");
    }
})->with([
    'with a lock file' => [true],
    'without a lock file' => [false],
]);

it('keeps the demo installer on the same tagged release line', function (): void {
    $script = File::get(dirname(__DIR__, 2).'/bin/molly-demo');

    expect($script)->toContain('MOLLY_CONSTRAINT="${MOLLY_CONSTRAINT:-'.InitializeMollyInExistingProject::RELEASE_CONSTRAINT.'}"')
        ->not->toContain('dev-main');
});

it('ships no install path that resolves dev-main', function (): void {
    $root = dirname(__DIR__, 2);
    $paths = ['README.md', 'bin/molly-demo', 'src/Actions/InitializeMollyInExistingProject.php', 'src/Actions/CreateMollyProject.php'];
    foreach (File::glob($root.'/docs/*.md') as $page) {
        $paths[] = substr($page, strlen($root) + 1);
    }

    foreach ($paths as $path) {
        expect(File::get($root.'/'.$path))->not->toMatch('/sifrious\/molly:dev-/', $path.' installs a development branch');
    }
});

it('stops before installation and restores the project when the release cannot resolve', function (string $error): void {
    writeComposerJson($this->laravelRoot);
    File::put($this->laravelRoot.'/composer.lock', '{"packages":[]}');
    $before = File::get($this->laravelRoot.'/composer.json');
    $root = $this->laravelRoot;
    Process::fake(function (PendingProcess $process) use ($root, $error) {
        if (($process->command[1] ?? null) === 'config') {
            File::put($root.'/composer.json', '{"repositories":{"molly":{"type":"vcs"}}}');

            return Process::result();
        }

        return Process::result(errorOutput: $error, exitCode: 2);
    });

    expect(fn () => initializeWithComposer($this->registry, $root))
        ->toThrow(RuntimeException::class, 'MOLLY_RELEASE_UNAVAILABLE: Composer could not resolve sifrious/molly:^0.2');
    expect(File::get($root.'/composer.json'))->toBe($before)
        ->and(File::get($root.'/composer.lock'))->toBe('{"packages":[]}')
        ->and(File::get($root.'/.gitignore'))->toBe("/vendor\n")
        ->and(File::exists($root.'/config/molly.php'))->toBeFalse()
        ->and(File::exists($root.'/.molly'))->toBeFalse()
        ->and(File::exists($this->mollyHome.'/projects.json'))->toBeFalse();
    Process::assertDidntRun(fn (PendingProcess $process): bool => ($process->command[1] ?? null) === 'require' && ! in_array('--dry-run', $process->command, true));
})->with(['No matching tag for ^0.2; available: v0.1.3', 'Repository connection failed']);

it('resolves dependencies without scripts before installing the stable release', function (): void {
    writeComposerJson($this->laravelRoot);
    $commands = [];
    Process::fake(function (PendingProcess $process) use (&$commands) {
        $commands[] = $process->command;

        return Process::result();
    });
    initializeWithComposer($this->registry, $this->laravelRoot);

    expect($commands[1])->toBe([
        'composer', 'require', '--dev', 'sifrious/molly:^0.2@stable',
        '--dry-run', '--no-interaction', '--no-plugins', '--no-scripts', '--no-cache',
    ])->and($commands[2])->toBe([
        'composer', 'require', '--dev', 'sifrious/molly:^0.2@stable', '--no-interaction',
    ])->and(File::exists($this->laravelRoot.'/.molly/project.json'))->toBeTrue();
});

it('initializes a separately installed unpublished candidate without resolving a public release', function (): void {
    writeComposerJson($this->laravelRoot, [
        'require-dev' => ['sifrious/molly' => '0.2.0-RC11'],
        'repositories' => ['molly-candidate' => ['type' => 'artifact', 'url' => '/acceptance/0.2.0-RC11']],
    ]);
    $before = File::get($this->laravelRoot.'/composer.json');
    File::ensureDirectoryExists($this->laravelRoot.'/vendor/composer');
    File::put($this->laravelRoot.'/vendor/composer/installed.json', json_encode(['packages' => [[
        'name' => 'sifrious/molly', 'version' => '0.2.0-RC11',
        'extra' => ['molly-candidate' => ['commit' => str_repeat('a', 40)]],
    ]]]));
    File::put($this->laravelRoot.'/vendor/composer/autoload_psr4.php', "<?php return ['Sifrious\\\\Molly\\\\' => ['/sifrious/molly/src']];");
    Process::fake(['*' => Process::result(errorOutput: 'Public repositories unavailable', exitCode: 1)]);

    initializeWithComposer($this->registry, $this->laravelRoot);

    Process::assertNothingRan();
    expect(File::get($this->laravelRoot.'/composer.json'))->toBe($before)
        ->and(File::exists($this->laravelRoot.'/.molly/project.json'))->toBeTrue();
});

it('reports a stopped release check without initializing the project', function (): void {
    writeComposerJson($this->laravelRoot);
    $before = File::get($this->laravelRoot.'/composer.json');
    Process::fake(function (PendingProcess $process) {
        if (in_array('--dry-run', (array) $process->command, true)) {
            throw new RuntimeException('Composer process timed out');
        }

        return Process::result();
    });

    expect(fn () => initializeWithComposer($this->registry, $this->laravelRoot))
        ->toThrow(RuntimeException::class, 'MOLLY_RELEASE_UNAVAILABLE');
    expect(File::get($this->laravelRoot.'/composer.json'))->toBe($before)
        ->and(File::exists($this->laravelRoot.'/.molly'))->toBeFalse();
});
