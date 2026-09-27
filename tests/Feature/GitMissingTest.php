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
