<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Actions\RecordVerificationReceipts;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Knowledge\LaravelVersion;
use Sifrious\Molly\Workspace\Directory;

beforeEach(function (): void {
    $this->project = realpath(sys_get_temp_dir()).'/molly-escape-project-'.Str::uuid();
    $this->outside = realpath(sys_get_temp_dir()).'/molly-escape-outside-'.Str::uuid();
    File::ensureDirectoryExists($this->project);
    File::ensureDirectoryExists($this->outside);
    File::put($this->project.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($this->project.'/composer.json', '{"name":"example/app"}');
    File::put($this->project.'/composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v13.0.0']], 'packages-dev' => []]));

    $this->basePath = base_path();
    app()->setBasePath($this->project);
    config()->set('molly.knowledge.database', '.molly/knowledge.sqlite');
});

afterEach(function (): void {
    app()->setBasePath($this->basePath);
    File::deleteDirectory($this->project);
    File::deleteDirectory($this->outside);
});

/** @return list<string> */
function filesUnder(string $directory): array
{
    return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
}

it('refuses to index into a knowledge database that links outside the workspace', function (): void {
    File::ensureDirectoryExists($this->project.'/.molly');
    File::put($this->outside.'/t.sqlite', '');
    symlink($this->outside.'/t.sqlite', $this->project.'/.molly/knowledge.sqlite');

    $status = Artisan::call('molly:knowledge:index', ['namespace' => 'laravel', '--laravel-version' => app(LaravelVersion::class)->current(), '--json' => true]);

    expect($status)->toBe(1)
        ->and(Artisan::output())->toContain('WORKSPACE_PATH_ESCAPE: '.$this->project.'/.molly/knowledge.sqlite is a symbolic link to '.$this->outside.'/t.sqlite')
        ->and(filesize($this->outside.'/t.sqlite'))->toBe(0);
});

it('refuses to bootstrap graphs through a linked .molly directory before creating anything', function (): void {
    symlink($this->outside, $this->project.'/.molly');

    $status = Artisan::call('molly:graphs-bootstrap', ['path' => $this->project, '--json' => true]);

    expect($status)->toBe(1)
        ->and(Artisan::output())->toContain('WORKSPACE_PATH_ESCAPE: '.$this->project.'/.molly is a symbolic link to '.$this->outside)
        ->and(filesUnder($this->outside))->toBe([]);
});

it('refuses a linked graphs directory before writing the manifest', function (): void {
    File::ensureDirectoryExists($this->project.'/.molly');
    symlink($this->outside, $this->project.'/.molly/graphs');

    expect(Artisan::call('molly:graphs-bootstrap', ['path' => $this->project, '--json' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('WORKSPACE_PATH_ESCAPE')
        ->and(filesUnder($this->outside))->toBe([]);
});

it('refuses to append lifecycle events through a linked .molly directory', function (): void {
    symlink($this->outside, $this->project.'/.molly');

    expect(fn () => app(RecordLifecycleEvent::class)->handle($this->project, LifecycleEventType::cases()[0], (string) Str::uuid()))
        ->toThrow(RuntimeException::class, 'WORKSPACE_PATH_ESCAPE');
    expect(filesUnder($this->outside))->toBe([]);
});

it('refuses to write receipts through a linked receipts directory', function (): void {
    File::ensureDirectoryExists($this->project.'/.molly');
    symlink($this->outside, $this->project.'/.molly/receipts');

    expect(fn () => app(RecordVerificationReceipts::class)->handle($this->project, (string) Str::uuid(), [
        'verification' => ['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'failures' => 0, 'errors' => 0, 'skipped' => 0],
        'verification_outcomes' => ['pest' => ['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry']],
    ]))->toThrow(RuntimeException::class, 'WORKSPACE_PATH_ESCAPE');
    expect(filesUnder($this->outside))->toBe([]);
});

it('names both paths when a .molly entry links elsewhere and allows real directories', function (): void {
    File::ensureDirectoryExists($this->project.'/.molly/worker');

    expect(Directory::molly($this->project, 'worker/worker.json'))->toBe($this->project.'/.molly/worker/worker.json')
        ->and(Directory::molly($this->project))->toBe($this->project.'/.molly');

    symlink($this->outside.'/identity.json', $this->project.'/.molly/identity.json');

    expect(fn () => Directory::molly($this->project, 'identity.json'))
        ->toThrow(RuntimeException::class, 'WORKSPACE_PATH_ESCAPE: '.$this->project.'/.molly/identity.json is a symbolic link to '.$this->outside.'/identity.json');
    expect(fn () => Directory::molly($this->project, '../outside'))
        ->toThrow(RuntimeException::class, 'WORKSPACE_PATH_ESCAPE');
});
