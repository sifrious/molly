<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace\BindWorkspaceReference;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-nogit-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::ensureDirectoryExists($this->workspace.'/tests');
    File::put($this->workspace.'/app/Hello.php', '<?php');
    File::put($this->workspace.'/tests/HelloTest.php', "<?php\nit('works', fn () => expect(true)->toBeTrue());\n");
    $this->before = File::allFiles($this->workspace, true);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('refuses a workspace that is not a Git repository and creates no repository, file, or commit', function () {
    expect(fn () => app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Hello.php'], 'tests/HelloTest.php'))
        ->toThrow(RuntimeException::class, 'WORKSPACE_NOT_GIT: '.realpath($this->workspace).' is not a Git repository. Run git init and commit your work, then try again.');

    expect(file_exists($this->workspace.'/.git'))->toBeFalse()
        ->and(file_exists($this->workspace.'/README.molly-bind'))->toBeFalse()
        ->and(file_exists($this->workspace.'/.molly'))->toBeFalse()
        ->and(File::allFiles($this->workspace, true))->toEqual($this->before)
        ->and(Task::count())->toBe(0);
});

it('reports WORKSPACE_NOT_GIT from molly:create before saving a task', function () {
    $exit = Artisan::call('molly:create', ['prompt' => 'Return Hello.', '--workspace' => $this->workspace, '--file' => ['app/Hello.php'], '--test' => 'tests/HelloTest.php', '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['error'])->toStartWith('WORKSPACE_NOT_GIT: ')
        ->and(file_exists($this->workspace.'/.git'))->toBeFalse()
        ->and(file_exists($this->workspace.'/README.molly-bind'))->toBeFalse()
        ->and(Task::count())->toBe(0);
});

it('refuses molly:demo before writing the demo files', function () {
    $exit = Artisan::call('molly:demo', ['--workspace' => $this->workspace, '--json' => true, '--no-interaction' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['error'])->toStartWith('WORKSPACE_NOT_GIT: ')
        ->and(File::allFiles($this->workspace, true))->toEqual($this->before)
        ->and(file_exists($this->workspace.'/.gitignore'))->toBeFalse()
        ->and(Task::count())->toBe(0);
});

it('refuses a repository with no commit without committing for the user', function () {
    (new Process(['git', 'init', '--quiet', $this->workspace]))->mustRun();

    expect(fn () => app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Hello.php'], 'tests/HelloTest.php'))
        ->toThrow(RuntimeException::class, 'WORKSPACE_REVISION_MISSING: ');

    $log = new Process(['git', '-C', $this->workspace, 'rev-parse', '--verify', '--quiet', 'HEAD']);
    $log->run();
    expect($log->getOutput())->toBe('')
        ->and(file_exists($this->workspace.'/README.molly-bind'))->toBeFalse();
});

it('reports a missing repository in molly:doctor without creating one', function () {
    Artisan::call('molly:doctor', ['--workspace' => $this->workspace, '--json' => true]);
    $checks = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'])->keyBy('name');

    expect($checks['Git repository']['status'])->toBe('failed')
        ->and($checks['Git repository']['code'])->toBe('workspace_not_git')
        ->and(file_exists($this->workspace.'/.git'))->toBeFalse()
        ->and(File::allFiles($this->workspace, true))->toEqual($this->before);
});

it('reports a missing repository in molly:status and still exits 0', function () {
    $exit = Artisan::call('molly:status', ['--workspace' => $this->workspace, '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $checks = collect($payload['readiness']['checks'])->keyBy('name');

    expect($exit)->toBe(0)
        ->and($checks['Git repository']['code'])->toBe('workspace_not_git')
        ->and(file_exists($this->workspace.'/.git'))->toBeFalse()
        ->and(File::allFiles($this->workspace, true))->toEqual($this->before);
});

it('passes the repository check for a committed checkout', function () {
    $head = commitGitWorkspace($this->workspace);
    Artisan::call('molly:doctor', ['--workspace' => $this->workspace, '--json' => true]);
    $checks = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'])->keyBy('name');

    expect($checks['Git repository']['status'])->toBe('passed')
        ->and($checks['Git repository']['code'])->toBe('git_repository')
        ->and($checks['Git repository']['message'])->toContain($head);
});

it('refuses to bind the Testbench skeleton and adds no repository to it', function () {
    $before = testbenchSkeletonGitMarkers();
    // A vendor tree used by an older Molly may already hold .git, so compare with what was there.
    $error = null;
    try {
        app(BindWorkspaceReference::class)->handle(base_path());
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }

    expect(testbenchSkeletonGitMarkers())->toBe($before);
    if ($before === []) {
        expect($error)->toStartWith('WORKSPACE_NOT_GIT: ');
    }
});

/** A Laravel app in backend/ of a repository whose root is one level up. */
function monorepoWithApp(): array
{
    $root = sys_get_temp_dir().'/molly-mono-'.Str::uuid();
    File::ensureDirectoryExists($root.'/backend/app');
    File::ensureDirectoryExists($root.'/backend/tests');
    File::put($root.'/README.md', "# mono\n");
    File::put($root.'/backend/app/Hello.php', '<?php');
    File::put($root.'/backend/tests/HelloTest.php', "<?php\nit('works', fn () => expect(true)->toBeTrue());\n");

    return [$root, $root.'/backend', commitGitWorkspace($root)];
}

it('accepts an app in a subdirectory of a repository in create, doctor, and status', function () {
    [$root, $app, $head] = monorepoWithApp();

    try {
        $task = app(CreateTask::class)->handle('Return Hello.', $app, ['app/Hello.php'], 'tests/HelloTest.php');
        $bound = app(BindWorkspaceReference::class)->handle($app);

        Artisan::call('molly:doctor', ['--workspace' => $app, '--json' => true]);
        $doctor = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'])->keyBy('name');
        $exit = Artisan::call('molly:status', ['--workspace' => $app, '--json' => true]);
        $status = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['readiness']['checks'])->keyBy('name');

        expect($task->workspace)->toBe(realpath($app))
            ->and($bound->currentPath)->toBe($app)
            ->and($bound->head?->sha)->toBe($head)
            ->and($bound->checkoutKind)->toBe('clone')
            ->and(is_dir($app.'/.molly'))->toBeTrue()
            ->and(file_exists($root.'/.molly'))->toBeFalse()
            ->and(file_exists($app.'/.git'))->toBeFalse()
            ->and($doctor['Git repository']['code'])->toBe('git_repository')
            ->and($doctor['Git repository']['message'])->toContain($head)->toContain(realpath($root))
            ->and($exit)->toBe(0)
            ->and($status['Git repository']['code'])->toBe('git_repository');
    } finally {
        File::deleteDirectory($root);
    }
});

it('accepts a linked worktree whose .git is a file', function () {
    [$root, $app] = monorepoWithApp();
    $worktree = $root.'-worktree';
    (new Process(['git', '-C', $root, 'worktree', 'add', '--quiet', '--detach', $worktree]))->mustRun();

    try {
        $task = app(CreateTask::class)->handle('Return Hello.', $worktree.'/backend', ['app/Hello.php'], 'tests/HelloTest.php');

        expect(is_file($worktree.'/.git'))->toBeTrue()
            ->and($task->workspace)->toBe(realpath($worktree.'/backend'))
            ->and(app(BindWorkspaceReference::class)->handle($worktree)->checkoutKind)->toBe('worktree');
    } finally {
        File::deleteDirectory($worktree);
        File::deleteDirectory($root);
    }
});

it('refuses an app the enclosing repository ignores without advising git init', function () {
    [$root] = monorepoWithApp();
    File::put($root.'/.gitignore', "scratch/\n");
    commitGitWorkspace($root, 'Ignore scratch');
    $app = $root.'/scratch/app';
    File::copyDirectory($root.'/backend', $app);

    try {
        expect(fn () => app(CreateTask::class)->handle('Return Hello.', $app, ['app/Hello.php'], 'tests/HelloTest.php'))
            ->toThrow(RuntimeException::class, 'WORKSPACE_NOT_GIT: '.realpath($app).' is ignored by the Git repository at '.realpath($root));

        Artisan::call('molly:doctor', ['--workspace' => $app, '--json' => true]);
        $doctor = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'])->keyBy('name');

        expect($doctor['Git repository']['code'])->toBe('workspace_not_git')
            ->and($doctor['Git repository']['message'])->not->toContain('git init')
            ->and(file_exists($app.'/.molly'))->toBeFalse()
            ->and(Task::count())->toBe(0);
    } finally {
        File::deleteDirectory($root);
    }
});
