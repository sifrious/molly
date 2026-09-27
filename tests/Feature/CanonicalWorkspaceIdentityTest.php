<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Projects\MollyProject;
use Sifrious\Molly\Projects\ProjectRegistry;

beforeEach(function (): void {
    $this->mollyHome = sys_get_temp_dir().'/molly-home-'.Str::uuid();
    File::ensureDirectoryExists($this->mollyHome);
    putenv('MOLLY_HOME='.$this->mollyHome);
    $_ENV['MOLLY_HOME'] = $this->mollyHome;

    $this->workspace = sys_get_temp_dir().'/molly-canonical-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Hello.php', '<?php');
    File::put($this->workspace.'/app/Welcome.php', '<?php');
    writeProtectedTest($this->workspace, 'tests/HelloTest.php');
    commitGitWorkspace($this->workspace);
});

afterEach(function (): void {
    File::deleteDirectory($this->workspace);
    File::deleteDirectory($this->mollyHome);
    putenv('MOLLY_HOME');
    unset($_ENV['MOLLY_HOME']);
});

function identityOf(Task $task): array
{
    return $task->only(['project_id', 'workspace_id', 'repository_id', 'checkout_id']);
}

it('gives two tasks in one checkout the same project, workspace, repository, and checkout IDs', function (): void {
    $first = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Hello.php'], 'tests/HelloTest.php');
    $second = app(CreateTask::class)->handle('Return Welcome.', $this->workspace.'/.', ['app/Welcome.php'], 'tests/HelloTest.php');

    expect(identityOf($second->fresh()))->toBe(identityOf($first->fresh()))
        ->and(Str::isUuid($first->project_id))->toBeTrue()
        ->and($first->workspace_id)->not->toBe($first->project_id);

    $stored = json_decode(File::get($this->workspace.'/.molly/identity.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($stored['workspace_id'])->toBe($first->workspace_id)
        ->and($stored['checkout_id'])->toBe($first->checkout_id);
});

it('records the HEAD revision each task was created at', function (): void {
    $first = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Hello.php'], 'tests/HelloTest.php');
    File::put($this->workspace.'/app/Welcome.php', "<?php\n// changed\n");
    Process::path($this->workspace)->run(['git', 'commit', '-qam', 'Change welcome'])->throw();
    $second = app(CreateTask::class)->handle('Return Welcome.', $this->workspace, ['app/Welcome.php'], 'tests/HelloTest.php');

    $head = trim(Process::path($this->workspace)->run(['git', 'rev-parse', 'HEAD'])->output());
    expect($second->base_sha)->toBe($head)
        ->and($first->base_sha)->not->toBe($head)
        ->and($second->workspace_id)->toBe($first->workspace_id);
});

it('uses the registry project ID for tasks in a registered project', function (): void {
    File::put($this->workspace.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($this->workspace.'/composer.json', json_encode(['require' => ['laravel/framework' => '^12.0']]));
    File::ensureDirectoryExists($this->workspace.'/vendor/sifrious/molly');

    $before = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Hello.php'], 'tests/HelloTest.php');

    $exit = Artisan::call('molly:project-init', [
        'path' => $this->workspace,
        '--no-composer' => true,
        '--no-migrate' => true,
        '--no-graphs' => true,
        '--json' => true,
    ]);
    $registryId = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['project']['id'];

    $after = app(CreateTask::class)->handle('Return Welcome.', $this->workspace, ['app/Welcome.php'], 'tests/HelloTest.php');

    expect($exit)->toBe(0)
        ->and($registryId)->toBe($before->project_id)
        ->and($after->project_id)->toBe($registryId)
        ->and((new ProjectRegistry($this->mollyHome))->readProject($this->workspace)?->id)->toBe($registryId);
});

it('uses an existing registry ID as the project ID', function (): void {
    $registry = new ProjectRegistry($this->mollyHome);
    $registry->writeProject(new MollyProject(
        id: '0b7a3f55-8f0e-4a5e-9d7f-4f6a2a8c1d10',
        name: 'registered',
        path: $this->workspace,
        source: 'existing',
        createdAt: gmdate('c'),
    ));

    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Hello.php'], 'tests/HelloTest.php');

    expect($task->project_id)->toBe('0b7a3f55-8f0e-4a5e-9d7f-4f6a2a8c1d10');
});

it('reads the same checkout identity from a separate PHP process', function (): void {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Hello.php'], 'tests/HelloTest.php');

    $script = <<<'PHP'
        require $argv[1].'/vendor/autoload.php';
        $app = new Illuminate\Container\Container;
        $app->instance('files', new Illuminate\Filesystem\Filesystem);
        Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        echo json_encode((new Sifrious\Molly\Projects\ProjectRegistry($argv[3]))->checkoutIdentity($argv[2]));
        PHP;
    $result = Process::run([PHP_BINARY, '-r', $script, dirname(__DIR__, 2), $this->workspace, $this->mollyHome])->throw();

    expect(json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR))->toBe(identityOf($task->fresh()));
});

it('refuses a damaged identity file instead of minting new IDs', function (): void {
    File::ensureDirectoryExists($this->workspace.'/.molly');
    File::put($this->workspace.'/.molly/identity.json', '{"project_id":"not-a-uuid"}');

    expect(fn () => app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Hello.php'], 'tests/HelloTest.php'))
        ->toThrow(RuntimeException::class, 'WORKSPACE_IDENTITY_INVALID');
});

it('records the HEAD revision a run started at and keeps the stored task IDs', function (): void {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Hello.php'], 'tests/HelloTest.php', requireRedBaseline: false);
    File::put($this->workspace.'/app/Welcome.php', "<?php\n// moved on\n");
    Process::path($this->workspace)->run(['git', 'commit', '-qam', 'Move on'])->throw();
    $head = trim(Process::path($this->workspace)->run(['git', 'rev-parse', 'HEAD'])->output());

    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(GenerateChanges::class)->shouldReceive('handle')->andReturn(['summary' => 'Return Hello.', 'files' => [['path' => 'app/Hello.php', 'content' => '<?php return "Hello";']]]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true]);
    $this->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle')->andReturn(['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']), 'findings' => []]);

    $run = app(RunTask::class)->handle('Return Hello.', $this->workspace, ['app/Hello.php'], 'tests/HelloTest.php', taskId: $task->id);

    expect($run->base_sha)->toBe($head)
        ->and($task->base_sha)->not->toBe($head)
        ->and($run->only(['project_id', 'workspace_id', 'repository_id', 'checkout_id']))->toBe(identityOf($task->fresh()));
});
