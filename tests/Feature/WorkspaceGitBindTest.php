<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Execution\Sandbox;
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

it('refuses molly:demo in a repository with no commit before writing the demo files', function () {
    (new Process(['git', 'init', '--quiet', $this->workspace]))->mustRun();
    $before = File::allFiles($this->workspace, true);

    $exit = Artisan::call('molly:demo', ['--workspace' => $this->workspace, '--json' => true, '--no-interaction' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['error'])->toStartWith('WORKSPACE_REVISION_MISSING: ')
        ->and(File::allFiles($this->workspace, true))->toEqual($before)
        ->and(file_exists($this->workspace.'/app/Greeting.php'))->toBeFalse()
        ->and(file_exists($this->workspace.'/tests/Feature/GreetingTest.php'))->toBeFalse()
        ->and(file_exists($this->workspace.'/.gitignore'))->toBeFalse()
        ->and(file_exists($this->workspace.'/.molly'))->toBeFalse()
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

/** A pending task whose workspace has since lost its repository and .molly/ directory. */
function pendingTaskWithoutRepository(string $workspace, bool $initialize = false): Task
{
    commitGitWorkspace($workspace);
    $task = app(CreateTask::class)->handle('Return Hello.', $workspace, ['app/Hello.php'], 'tests/HelloTest.php');
    File::deleteDirectory($workspace.'/.git');
    File::deleteDirectory($workspace.'/.molly');
    if ($initialize) {
        (new Process(['git', 'init', '--quiet', $workspace]))->mustRun();
    }

    return $task;
}

function refuseSandbox(): void
{
    $sandbox = new Sandbox;
    (fn () => $this->available = false)->call($sandbox);
    app()->instance(Sandbox::class, $sandbox);
    config(['molly.sandbox.allow_unsafe' => false]);
}

it('refuses molly:start and molly:retry outside a repository before the sandbox check and writes nothing', function (string $command, string $status) {
    $task = pendingTaskWithoutRepository($this->workspace);
    $task->update(['status' => $status]);
    $before = File::allFiles($this->workspace, true);
    refuseSandbox();

    $exit = Artisan::call($command, ['task' => $task->id, '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['report']['error'])->toStartWith('WORKSPACE_NOT_GIT: ')
        ->and(file_exists($this->workspace.'/.molly'))->toBeFalse()
        ->and(file_exists($this->workspace.'/.git'))->toBeFalse()
        ->and(File::allFiles($this->workspace, true))->toEqual($before)
        ->and($task->fresh()->status)->toBe($status)
        ->and($task->fresh()->attempt_number)->toBe(0);
})->with([
    'start' => ['molly:start', 'pending'],
    'retry' => ['molly:retry', 'failed'],
]);

it('refuses molly:start in a repository with no commit before the sandbox check and writes nothing', function () {
    $task = pendingTaskWithoutRepository($this->workspace, initialize: true);
    $before = File::allFiles($this->workspace, true);
    refuseSandbox();

    $exit = Artisan::call('molly:start', ['task' => $task->id, '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['report']['error'])->toStartWith('WORKSPACE_REVISION_MISSING: ')
        ->and(file_exists($this->workspace.'/.molly'))->toBeFalse()
        ->and(File::allFiles($this->workspace, true))->toEqual($before)
        ->and($task->fresh()->status)->toBe('pending');
});

it('reports GIT_MISSING before WORKSPACE_NOT_GIT from molly:start', function () {
    $task = pendingTaskWithoutRepository($this->workspace);
    $path = getenv('PATH');
    putenv('PATH='.sys_get_temp_dir().'/molly-no-git-'.Str::uuid());

    try {
        $exit = Artisan::call('molly:start', ['task' => $task->id, '--json' => true]);
    } finally {
        putenv('PATH='.$path);
    }
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['report']['error'])->toStartWith('GIT_MISSING: ')
        ->and(file_exists($this->workspace.'/.molly'))->toBeFalse();
});

it('reports a running task as not pending, not as a sandbox refusal', function () {
    commitGitWorkspace($this->workspace);
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Hello.php'], 'tests/HelloTest.php');
    $task->update(['status' => 'running', 'worker_id' => 'live-worker', 'lease_expires_at' => now()->addMinutes(10)]);
    refuseSandbox();

    $exit = Artisan::call('molly:start', ['task' => $task->id, '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['report']['error'])->toStartWith('TASK_NOT_PENDING: ')
        ->and($task->fresh()->only(['status', 'worker_id']))->toBe(['status' => 'running', 'worker_id' => 'live-worker']);
});

/** A Laravel app in backend/ that is neither tracked nor ignored by a committed repository. */
function monorepoWithUntrackedApp(): array
{
    $root = sys_get_temp_dir().'/molly-mono-untracked-'.Str::uuid();
    File::ensureDirectoryExists($root);
    File::put($root.'/README.md', "# mono\n");
    commitGitWorkspace($root);
    File::ensureDirectoryExists($root.'/backend/app');
    File::ensureDirectoryExists($root.'/backend/tests');
    File::put($root.'/backend/app/Hello.php', '<?php');
    File::put($root.'/backend/tests/HelloTest.php', "<?php\nit('works', fn () => expect(true)->toBeTrue());\n");
    File::put($root.'/backend/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($root.'/backend/composer.json', json_encode(['name' => 'example/app', 'require' => ['laravel/framework' => '^12.0']]));

    return [$root, $root.'/backend'];
}

it('refuses an untracked app in a committed repository in create, project-init, and demo without writing', function () {
    [$root, $app] = monorepoWithUntrackedApp();
    $advice = 'git -C '.escapeshellarg(realpath($root)).' add '.escapeshellarg('backend');
    $before = File::allFiles($app, true);

    try {
        expect(fn () => app(CreateTask::class)->handle('Return Hello.', $app, ['app/Hello.php'], 'tests/HelloTest.php'))
            ->toThrow(RuntimeException::class, 'WORKSPACE_REVISION_MISSING: '.$app.' has no files in the HEAD commit of the Git repository at '.realpath($root));

        foreach ([
            ['molly:create', ['prompt' => 'Return Hello.', '--workspace' => $app, '--file' => ['app/Hello.php'], '--test' => 'tests/HelloTest.php', '--json' => true]],
            ['molly:project-init', ['path' => $app, '--no-composer' => true, '--no-graphs' => true, '--no-migrate' => true, '--json' => true]],
            ['molly:demo', ['--workspace' => $app, '--json' => true, '--no-interaction' => true]],
        ] as [$command, $arguments]) {
            $exit = Artisan::call($command, $arguments);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            expect($exit)->toBe(1)
                ->and($payload['error'])->toStartWith('WORKSPACE_REVISION_MISSING: ')
                ->and($payload['error'])->toContain($advice)
                ->and($payload['error'])->not->toContain('git init');
        }

        expect(File::allFiles($app, true))->toEqual($before)
            ->and(file_exists($app.'/.molly'))->toBeFalse()
            ->and(file_exists($app.'/.gitignore'))->toBeFalse()
            ->and(file_exists($app.'/.git'))->toBeFalse()
            ->and(Task::count())->toBe(0);
    } finally {
        File::deleteDirectory($root);
    }
});

it('reports an untracked app in molly:doctor and molly:status', function () {
    [$root, $app] = monorepoWithUntrackedApp();

    try {
        Artisan::call('molly:doctor', ['--workspace' => $app, '--json' => true]);
        $doctor = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'])->keyBy('name');
        $exit = Artisan::call('molly:status', ['--workspace' => $app, '--json' => true]);
        $status = collect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['readiness']['checks'])->keyBy('name');

        expect($doctor['Git repository']['status'])->toBe('failed')
            ->and($doctor['Git repository']['code'])->toBe('workspace_revision_missing')
            ->and($doctor['Git repository']['message'])->toContain('has no files in the HEAD commit')
            ->and($doctor['Git repository']['message'])->not->toContain('git init')
            ->and($exit)->toBe(0)
            ->and($status['Git repository']['code'])->toBe('workspace_revision_missing')
            ->and(file_exists($app.'/.molly'))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
});

it('refuses molly:start when the app has left the HEAD commit, before the sandbox check', function () {
    [$root, $app] = monorepoWithApp();
    $task = app(CreateTask::class)->handle('Return Hello.', $app, ['app/Hello.php'], 'tests/HelloTest.php');
    (new Process(['git', '-C', $root, 'rm', '-r', '--cached', '--quiet', 'backend']))->mustRun();
    (new Process(['git', '-c', 'user.name=Molly Tests', '-c', 'user.email=tests@example.com', '-c', 'commit.gpgsign=false', '-C', $root, 'commit', '--quiet', '-m', 'Stop tracking backend']))->mustRun();
    refuseSandbox();

    try {
        $exit = Artisan::call('molly:start', ['task' => $task->id, '--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(1)
            ->and($payload['report']['error'])->toStartWith('WORKSPACE_REVISION_MISSING: ')
            ->and($payload['report']['error'])->toContain('has no files in the HEAD commit')
            ->and($task->fresh()->only(['status', 'attempt_number']))->toBe(['status' => 'pending', 'attempt_number' => 0]);
    } finally {
        File::deleteDirectory($root);
    }
});
