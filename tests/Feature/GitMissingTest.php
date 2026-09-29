<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CheckEnvironment;
use Sifrious\Molly\Models\Task;

/** @return array<string, mixed> */
function jsonCommand(string $command, array $arguments): array
{
    $exit = Artisan::call($command, [...$arguments, '--json' => true, '--no-interaction' => true]);

    return ['exit' => $exit, ...json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

beforeEach(function (): void {
    $this->workspace = sys_get_temp_dir().'/molly-nogit-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::ensureDirectoryExists($this->workspace.'/tests/Feature');
    File::put($this->workspace.'/tests/Feature/GreetingTest.php', "<?php\n\nit('greets', fn () => expect(true)->toBeTrue());\n");

    // A PATH that holds only an empty directory, so no git executable can be found.
    File::ensureDirectoryExists($this->workspace.'/empty-bin');
    $this->path = getenv('PATH');
    putenv('PATH='.$this->workspace.'/empty-bin');
});

afterEach(function (): void {
    putenv('PATH='.$this->path);
    File::deleteDirectory($this->workspace);
});

it('names missing git in molly:doctor with git_missing', function (): void {
    $checks = array_column(app(CheckEnvironment::class)->handle($this->workspace)['checks'], null, 'name');

    expect($checks['Git']['status'])->toBe('failed')
        ->and($checks['Git']['code'])->toBe('git_missing')
        ->and($checks['Git']['message'])->toContain('Install Git');

    putenv('PATH='.$this->path);
    $checks = array_column(app(CheckEnvironment::class)->handle($this->workspace)['checks'], null, 'name');
    expect($checks['Git']['code'])->toBe('git_ready');
});

it('names GIT_MISSING in the Git repository check instead of reporting a repository with no commit', function (): void {
    putenv('PATH='.$this->path);
    commitGitWorkspace($this->workspace);
    putenv('PATH='.$this->workspace.'/empty-bin');

    $checks = array_column(app(CheckEnvironment::class)->handle($this->workspace)['checks'], null, 'name');

    expect($checks['Git repository']['code'])->toBe('git_missing')
        ->and($checks['Git repository']['status'])->toBe('unknown')
        ->and($checks['Git repository']['message'])->toContain('GIT_MISSING')->not->toContain('no commit yet');

    putenv('PATH='.$this->path);
    $checks = array_column(app(CheckEnvironment::class)->handle($this->workspace)['checks'], null, 'name');
    expect($checks['Git repository']['code'])->toBe('git_repository');
});

it('fails molly:create with GIT_MISSING before saving a task', function (): void {
    $result = jsonCommand('molly:create', ['prompt' => 'Return Hello', '--workspace' => $this->workspace, '--file' => ['app/Greeting.php'], '--test' => 'tests/Feature/GreetingTest.php']);

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('GIT_MISSING')
        ->and(Task::count())->toBe(0)
        ->and(File::exists($this->workspace.'/.git'))->toBeFalse();
});

it('fails molly:demo with GIT_MISSING before writing the demo files', function (): void {
    $result = jsonCommand('molly:demo', ['--workspace' => $this->workspace]);

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('GIT_MISSING')
        ->and(File::exists($this->workspace.'/app/Greeting.php'))->toBeFalse()
        ->and(File::exists($this->workspace.'/.gitignore'))->toBeFalse()
        ->and(File::get($this->workspace.'/tests/Feature/GreetingTest.php'))->toContain("it('greets'");
});

it('fails molly:review-commit with GIT_MISSING instead of an invalid commit', function (): void {
    $result = jsonCommand('molly:review-commit', ['--workspace' => $this->workspace]);

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('GIT_MISSING');
});

it('fails molly:setup with GIT_MISSING before writing .env', function (): void {
    File::put($this->workspace.'/.env', "APP_NAME=Example\n");
    $environmentPath = app()->environmentPath();
    app()->useEnvironmentPath($this->workspace);

    try {
        $result = jsonCommand('molly:setup', ['--agent' => 'ollama', '--model' => 'qwen2.5-coder:7b']);
    } finally {
        app()->useEnvironmentPath($environmentPath);
    }

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('GIT_MISSING')
        ->and(File::get($this->workspace.'/.env'))->toBe("APP_NAME=Example\n");
});

it('fails molly:project-init with GIT_MISSING before writing .gitignore, project.json, or the registry', function (): void {
    $home = $this->workspace.'/molly-home';
    putenv('MOLLY_HOME='.$home);
    File::put($this->workspace.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($this->workspace.'/composer.json', '{"name":"example/app","require":{"laravel/framework":"^13.0"}}');

    try {
        $result = jsonCommand('molly:project-init', ['path' => $this->workspace, '--no-composer' => true, '--no-graphs' => true, '--no-migrate' => true]);
    } finally {
        putenv('MOLLY_HOME');
    }

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('GIT_MISSING')
        ->and(File::exists($this->workspace.'/.gitignore'))->toBeFalse()
        ->and(File::exists($this->workspace.'/.molly'))->toBeFalse()
        ->and(File::exists($home))->toBeFalse();
});

it('fails molly:project-new with GIT_MISSING before creating the target', function (): void {
    $target = $this->workspace.'/fresh';

    $result = jsonCommand('molly:project-new', ['path' => $target, '--no-composer' => true, '--no-graphs' => true, '--no-migrate' => true]);

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toStartWith('GIT_MISSING')
        ->and(File::exists($target))->toBeFalse();
});

it('reports git_missing from molly:status instead of failing to start git', function (): void {
    $result = jsonCommand('molly:status', ['--workspace' => $this->workspace]);
    $checks = array_column($result['readiness']['checks'], null, 'name');

    expect($result['exit'])->toBe(0)
        ->and($result['status'])->toBe('ok')
        ->and($checks['Git']['code'])->toBe('git_missing')
        ->and($result['graphs']['status'])->toBe('missing');
});

it('fails molly:run with GIT_MISSING instead of SANDBOX_UNAVAILABLE', function (): void {
    $result = jsonCommand('molly:run', ['prompt' => 'Return Hello', '--workspace' => $this->workspace, '--file' => ['app/Greeting.php'], '--test' => 'tests/Feature/GreetingTest.php']);

    expect($result['exit'])->toBe(1)
        ->and($result['report']['error'])->toStartWith('GIT_MISSING')
        ->and(File::exists($this->workspace.'/.molly'))->toBeFalse();
});
