<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateMollyProject;
use Sifrious\Molly\Actions\InitializeMollyInExistingProject;
use Sifrious\Molly\Projects\MollyProject;
use Sifrious\Molly\Projects\ProjectRegistry;

/*
 * M02.4: an interrupted install leaves no project marked ready, and running the same command
 * again finishes it. Each case forks the test process and sends SIGKILL to the child at one
 * point of molly:project-init, so no catch, finally, or shutdown code runs, as with kill -9.
 * The parent then reruns the command and checks every file the install writes.
 */

/**
 * Stand in for Composer and for the application's Artisan. config and require write what
 * Composer writes, in Composer's order: composer.json, composer.lock, the package files,
 * vendor/composer/installed.json, then the autoload map. $reach names each point so a
 * forked child can die there.
 */
function fakeInstallProcesses(string $root, Closure $reach): void
{
    Process::fake(function (PendingProcess $process) use ($root, $reach) {
        $command = (array) $process->command;
        if ($command[0] === PHP_BINARY) {
            $reach('migrate:running');

            return Process::result('Nothing to migrate.');
        }

        $composer = json_decode(File::get($root.'/composer.json'), true);
        if ($command[1] === 'config') {
            $composer['repositories']['molly'] = ['type' => 'vcs', 'url' => $command[4]];
            File::put($root.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $reach('composer:config');

            return Process::result();
        }

        $composer['require-dev']['sifrious/molly'] = InitializeMollyInExistingProject::RELEASE_CONSTRAINT;
        File::put($root.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        File::put($root.'/composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v12.0.0']], 'packages-dev' => [['name' => 'sifrious/molly', 'version' => '0.2.0']]], JSON_PRETTY_PRINT)."\n");
        File::ensureDirectoryExists($root.'/vendor/sifrious/molly/src');
        File::put($root.'/vendor/sifrious/molly/composer.json', '{"name": "sifrious/molly"}');
        $reach('composer:extracted');
        File::ensureDirectoryExists($root.'/vendor/composer');
        File::put($root.'/vendor/composer/installed.json', json_encode(['packages' => [['name' => 'sifrious/molly', 'version' => '0.2.0']], 'dev' => true]));
        $reach('composer:autoload');
        File::put($root.'/vendor/composer/autoload_psr4.php', "<?php\n\n\$vendorDir = dirname(__DIR__);\n\nreturn array(\n    'Sifrious\\\\Molly\\\\' => array(\$vendorDir . '/sifrious/molly/src'),\n);\n");

        return Process::result();
    });
}

/** The point a progress note marks. The second graphs note means every graph step finished. */
function installPoint(string $step, string $message): string
{
    return $step === 'graphs' && str_starts_with($message, 'Knowledge graphs ') ? 'graphs:done' : $step;
}

/**
 * Run $install in a forked child that sends itself SIGKILL at $point, and return the signal
 * that ended it. A child that never reaches $point ends with SIGUSR1 instead.
 */
function killInstallAt(string $point, Closure $install): int
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('The test could not fork.');
    }
    if ($pid === 0) {
        $reach = static function (string $reached) use ($point): void {
            if ($reached === $point) {
                posix_kill(getmypid(), SIGKILL);
            }
        };
        try {
            $install($reach);
        } catch (Throwable) {
        }
        posix_kill(getmypid(), SIGUSR1);
    }

    pcntl_waitpid($pid, $status);

    return pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : -1;
}

/** @return list<string> */
function registeredProjects(string $home): array
{
    return is_file($home.'/projects.json') ? json_decode(File::get($home.'/projects.json'), true, flags: JSON_THROW_ON_ERROR) : [];
}

/** Files an interrupted write could leave: staged copies beside the records Molly replaces. */
function stagedInstallFiles(string $root, string $home): array
{
    return [
        ...glob($root.'/.molly/.*.tmp') ?: [],
        ...glob($root.'/config/.*.molly-*') ?: [],
        ...glob($home.'/.*.tmp') ?: [],
    ];
}

beforeEach(function (): void {
    $this->mollyHome = sys_get_temp_dir().'/molly-home-'.Str::uuid();
    File::ensureDirectoryExists($this->mollyHome);
    putenv('MOLLY_HOME='.$this->mollyHome);
    $_ENV['MOLLY_HOME'] = $this->mollyHome;
    $this->knowledgeDatabase = sys_get_temp_dir().'/molly-knowledge-'.Str::uuid().'.sqlite';
    config(['molly.knowledge.database' => $this->knowledgeDatabase]);

    $this->root = sys_get_temp_dir().'/molly-interrupted-'.Str::uuid();
    File::ensureDirectoryExists($this->root);
    File::put($this->root.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($this->root.'/composer.json', json_encode(['name' => 'example/app', 'require' => ['laravel/framework' => '^12.0']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    File::put($this->root.'/composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v12.0.0']], 'packages-dev' => []], JSON_PRETTY_PRINT)."\n");
    File::put($this->root.'/.gitignore', "/vendor\n");
    commitGitWorkspace($this->root);
    $this->root = str_replace('\\', '/', realpath($this->root));
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
    File::deleteDirectory($this->mollyHome);
    foreach (['', '-wal', '-shm'] as $suffix) {
        File::delete($this->knowledgeDatabase.$suffix);
    }
    putenv('MOLLY_HOME');
    unset($_ENV['MOLLY_HOME']);
});

it('finishes molly:project-init on the next run after a kill at each install step', function (string $point): void {
    $root = $this->root;
    $signal = killInstallAt($point, function (Closure $reach) use ($root): void {
        fakeInstallProcesses($root, $reach);
        app(InitializeMollyInExistingProject::class)->handle(
            path: $root,
            progress: fn (string $step, string $message) => $reach(installPoint($step, $message)),
        );
    });
    expect($signal)->toBe(SIGKILL, 'The install never reached '.$point.'.');

    // A killed install is never listed as ready, except after the last step.
    if ($point !== 'register') {
        expect(File::exists($root.'/.molly/project.json'))->toBeFalse()
            ->and(registeredProjects($this->mollyHome))->toBe([]);
    }

    fakeInstallProcesses($root, fn (string $reached) => null);
    $exit = Artisan::call('molly:project-init', ['path' => $root, '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $record = File::get($root.'/.molly/project.json');
    $composer = json_decode(File::get($root.'/composer.json'), true);
    $identity = json_decode(File::get($root.'/.molly/identity.json'), true);

    expect($exit)->toBe(0, (string) ($payload['error'] ?? ''))
        ->and($payload['status'])->toBe('initialized')
        ->and($payload['graphs']['ok'])->toBeTrue()
        ->and(registeredProjects($this->mollyHome))->toBe([$root])
        ->and(MollyProject::fromArray(json_decode($record, true))->id)->toBe($identity['project_id'])
        ->and(preg_match_all('/^\.molly\/$/m', File::get($root.'/.gitignore')))->toBe(1)
        ->and(File::get($root.'/config/molly.php'))->toBe(File::get(dirname(__DIR__, 2).'/config/molly.php'))
        ->and($composer['repositories'])->toBe(['molly' => ['type' => 'vcs', 'url' => InitializeMollyInExistingProject::REPOSITORY_URL]])
        ->and($composer['require-dev'])->toBe(['sifrious/molly' => InitializeMollyInExistingProject::RELEASE_CONSTRAINT])
        ->and(File::get($root.'/vendor/composer/autoload_psr4.php'))->toContain('/sifrious/molly/src')
        ->and(json_decode(File::get($root.'/.molly/graphs/manifest.json'), true)['units'][0]['status'])->toBe('ready')
        ->and(stagedInstallFiles($root, $this->mollyHome))->toBe([]);

    // A third run changes nothing Molly recorded.
    expect(Artisan::call('molly:project-init', ['path' => $root, '--json' => true]))->toBe(0)
        ->and(registeredProjects($this->mollyHome))->toBe([$root])
        ->and(File::get($root.'/.molly/project.json'))->toBe($record);
})->with([
    'before Composer' => ['validate'],
    'before composer config' => ['composer'],
    'after composer config wrote the repository' => ['composer:config'],
    'after Composer extracted the package' => ['composer:extracted'],
    'before Composer wrote the autoload map' => ['composer:autoload'],
    'after .gitignore' => ['gitignore'],
    'before config/molly.php' => ['config'],
    'before migrations' => ['migrate'],
    'while migrations run' => ['migrate:running'],
    'before the graphs' => ['graphs'],
    'while the Laravel graph builds' => ['graphs:laravel'],
    'after the graph manifest' => ['graphs:manifest'],
    'after the graphs, before registration' => ['graphs:done'],
    'after registration' => ['register'],
]);

it('runs composer require again when a killed install left the package directory behind', function (): void {
    $root = $this->root;
    killInstallAt('composer:extracted', function (Closure $reach) use ($root): void {
        fakeInstallProcesses($root, $reach);
        app(InitializeMollyInExistingProject::class)->handle(path: $root, runMigrations: false, bootstrapGraphs: false);
    });
    expect(File::isDirectory($root.'/vendor/sifrious/molly'))->toBeTrue();

    fakeInstallProcesses($root, fn (string $reached) => null);
    $result = app(InitializeMollyInExistingProject::class)->handle(path: $root, runMigrations: false, bootstrapGraphs: false);

    expect($result['steps'])->toContain('composer: Requiring sifrious/molly via Composer');
    Process::assertRan(fn (PendingProcess $process): bool => ($process->command[1] ?? null) === 'require');
    Process::assertDidntRun(fn (PendingProcess $process): bool => ($process->command[1] ?? null) === 'config');
});

it('skips composer require only when Composer recorded Molly and its autoload map', function (): void {
    $root = $this->root;
    fakeInstallProcesses($root, fn (string $reached) => null);
    app(InitializeMollyInExistingProject::class)->handle(path: $root, runMigrations: false, bootstrapGraphs: false);
    $result = app(InitializeMollyInExistingProject::class)->handle(path: $root, runMigrations: false, bootstrapGraphs: false);

    expect($result['steps'])->toContain('composer: Molly package already present');
    Process::assertRanTimes(fn (PendingProcess $process): bool => ($process->command[1] ?? null) === 'require', 1);
});

it('replaces projects.json and project.json by rename, so an interrupted write leaves the previous file whole', function (): void {
    $registry = new ProjectRegistry($this->mollyHome);
    $other = sys_get_temp_dir().'/molly-other-'.Str::uuid();
    File::ensureDirectoryExists($other);
    $other = str_replace('\\', '/', realpath($other));

    try {
        $registry->writeProject(new MollyProject((string) Str::uuid(), 'First', $this->root, 'existing', gmdate('c')));
        $index = File::get($this->mollyHome.'/projects.json');
        $record = File::get($this->root.'/.molly/project.json');
        $openIndex = fopen($this->mollyHome.'/projects.json', 'r');
        $openRecord = fopen($this->root.'/.molly/project.json', 'r');

        $registry->registerPath($other);
        $registry->writeProject(new MollyProject((string) Str::uuid(), 'Renamed', $this->root, 'existing', gmdate('c')));

        // A handle opened before the write still reads the complete previous file: Molly never
        // truncated it in place, so a kill mid-write cannot leave a half-written record.
        expect(stream_get_contents($openIndex))->toBe($index)
            ->and(stream_get_contents($openRecord))->toBe($record)
            ->and(registeredProjects($this->mollyHome))->toBe(collect([$this->root, $other])->sort()->values()->all())
            ->and($registry->readProject($this->root)->name)->toBe('Renamed')
            ->and(decoct(fileperms($this->mollyHome.'/projects.json') & 0777))->toBe('600')
            ->and(stagedInstallFiles($this->root, $this->mollyHome))->toBe([]);
        fclose($openIndex);
        fclose($openRecord);
    } finally {
        File::deleteDirectory($other);
    }
});

it('refuses to overwrite a directory left by an interrupted molly:project-new and replaces it with --force', function (): void {
    $target = sys_get_temp_dir().'/molly-new-interrupted-'.Str::uuid().'/app';
    // composer create-project writes the application files one by one.
    $fakeCreateProject = fn (Closure $reach) => Process::fake(function (PendingProcess $process) use ($target, $reach) {
        File::ensureDirectoryExists($target.'/vendor');
        File::put($target.'/composer.json', '{"name": "laravel/laravel"}');
        $reach('create-project');
        File::put($target.'/artisan', "#!/usr/bin/env php\n<?php\n");

        return Process::result();
    });

    try {
        $signal = killInstallAt('create-project', function (Closure $reach) use ($target, $fakeCreateProject): void {
            $fakeCreateProject($reach);
            app(CreateMollyProject::class)->handle(path: $target, runMigrations: false, bootstrapGraphs: false);
        });
        expect($signal)->toBe(SIGKILL)
            ->and(File::exists($target.'/composer.json'))->toBeTrue()
            ->and(File::exists($target.'/artisan'))->toBeFalse();

        $exit = Artisan::call('molly:project-new', ['path' => $target, '--json' => true]);
        $refused = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($exit)->toBe(1)
            ->and($refused['error'])->toBe('PROJECT_PATH_NOT_EMPTY: '.$target.' is not empty. Choose an empty directory, or pass --force to delete it and create the application again, for example after an interrupted molly:project-new.')
            ->and(registeredProjects($this->mollyHome))->toBe([]);

        $fakeCreateProject(fn (string $reached) => null);
        $exit = Artisan::call('molly:project-new', ['path' => $target, '--force' => true, '--json' => true]);
        $created = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        // The new app is not in a commit yet, so Molly stops and names the commands to run.
        expect($exit)->toBe(0)
            ->and($created['status'])->toBe('needs_commit')
            ->and(File::exists($target.'/artisan'))->toBeTrue()
            ->and(File::exists($target.'/.molly'))->toBeFalse()
            ->and(registeredProjects($this->mollyHome))->toBe([]);
    } finally {
        File::deleteDirectory(dirname($target));
    }
});
