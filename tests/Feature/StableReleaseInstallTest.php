<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
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
    (new InitializeMollyInExistingProject($registry))->handle(
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
        'composer', 'require', '--dev', 'sifrious/molly:'.InitializeMollyInExistingProject::RELEASE_CONSTRAINT, '--no-interaction',
    ]);
    Process::assertDidntRun(fn (PendingProcess $process): bool => str_contains(implode(' ', (array) $process->command), 'dev-main'));
});

it('leaves an existing Molly repository entry alone', function (): void {
    writeComposerJson($this->laravelRoot, ['repositories' => ['molly' => ['type' => 'vcs', 'url' => 'https://github.com/sifrious/molly']]]);
    Process::fake();

    initializeWithComposer($this->registry, $this->laravelRoot);

    Process::assertDidntRun(fn (PendingProcess $process): bool => ($process->command[1] ?? null) === 'config');
    Process::assertRanTimes(fn (PendingProcess $process): bool => ($process->command[1] ?? null) === 'require', 1);
});

it('reports a failed Composer require without continuing', function (): void {
    writeComposerJson($this->laravelRoot);
    Process::fake(['*' => Process::result(errorOutput: 'Could not find a version', exitCode: 1)]);

    expect(fn () => initializeWithComposer($this->registry, $this->laravelRoot))
        ->toThrow(RuntimeException::class, 'COMPOSER_REQUIRE_FAILED');
});

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
