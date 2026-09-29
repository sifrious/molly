<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace\ObserveCheckout;

/*
 * A relative path must resolve once, against the directory the command runs in. The app sits
 * at PARENT/app and the commands run from PARENT/one/two, so ../../app names it. Resolved a
 * second time from inside the app, the same path would name PARENT/../app, which is wrong.
 */
beforeEach(function (): void {
    $this->mollyHome = sys_get_temp_dir().'/molly-home-'.Str::uuid();
    File::ensureDirectoryExists($this->mollyHome);
    putenv('MOLLY_HOME='.$this->mollyHome);
    $_ENV['MOLLY_HOME'] = $this->mollyHome;
    $this->parent = '/tmp/molly-relative-'.bin2hex(random_bytes(8));
    $this->root = $this->parent.'/app';
    File::ensureDirectoryExists($this->root.'/app');
    File::put($this->root.'/app/Hello.php', '<?php');
    File::put($this->root.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($this->root.'/composer.json', json_encode(['require' => ['laravel/framework' => '^12.0']]));
    File::put($this->root.'/composer.lock', json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v12.0.0']], 'packages-dev' => []]));
    writeProtectedTest($this->root, 'tests/HelloTest.php');
    $this->head = commitGitWorkspace($this->root);
    File::ensureDirectoryExists($this->parent.'/one/two');
    $this->cwd = getcwd();
    chdir($this->parent.'/one/two');
});

afterEach(function (): void {
    chdir($this->cwd);
    File::deleteDirectory($this->parent);
    File::deleteDirectory($this->mollyHome);
    putenv('MOLLY_HOME');
    unset($_ENV['MOLLY_HOME']);
});

it('observes a relative checkout path once and reports it as an absolute path', function (): void {
    $observe = app(ObserveCheckout::class);

    expect($observe->handle('../../app'))->toMatchArray(['path' => realpath($this->root), 'head' => $this->head])
        ->and($observe->head('../../app'))->toBe($this->head)
        ->and($observe->headContainsFiles('../../app'))->toBeTrue()
        ->and(ObserveCheckout::locate('../../app')['root'])->toBe(realpath($this->root))
        ->and($observe->requireCommit('../../app'))->toBe($this->head);
});

it('saves a task from a relative --workspace at the real path', function (): void {
    [$exit, $result] = mollyJson('molly:create', ['prompt' => 'Return Hello.', '--workspace' => '../../app', '--test' => 'tests/HelloTest.php', '--file' => ['app/Hello.php']]);

    expect($exit)->toBe(0, json_encode($result))
        ->and(Task::findOrFail($result['id'])->only(['workspace', 'base_sha']))->toBe(['workspace' => realpath($this->root), 'base_sha' => $this->head]);
});

it('reads the checkout revision for a relative --workspace', function (): void {
    [$exit, $result] = mollyJson('molly:knowledge:query', ['concept' => 'route', '--workspace' => '../../app']);

    expect($exit)->toBe(0)
        ->and($result['freshness']['current']['revision'])->toBe($this->head);
});

it('reports a relative molly:project-new target as an absolute path', function (): void {
    File::ensureDirectoryExists($this->parent.'/repo');
    commitGitWorkspace($this->parent.'/repo');
    chdir($this->parent.'/repo');
    $target = realpath($this->parent.'/repo').'/fresh';

    [$exit, $result] = mollyJson('molly:project-new', ['path' => '../repo/fresh', '--no-composer' => true, '--no-migrate' => true, '--no-graphs' => true]);

    expect($exit)->toBe(0)
        ->and($result['status'])->toBe('needs_commit')
        ->and($result['path'])->toBe($target)
        ->and($result['next'][2])->toBe('php artisan molly:project-init '.escapeshellarg($target));
});

it('gives the same result for a relative and an absolute path', function (string $command, array $parameters, string $field): void {
    $call = function (string $path) use ($command, $parameters, $field): array {
        [$exit, $result] = mollyJson($command, json_decode(str_replace('@', $path, json_encode($parameters, JSON_UNESCAPED_SLASHES)), true));

        return [$exit, data_get($result, $field)];
    };

    expect($call('../../app'))->toBe($call($this->root));
})->with([
    'doctor' => ['molly:doctor', ['--workspace' => '@'], 'checks.3'],
    'status' => ['molly:status', ['--workspace' => '@'], 'workspace'],
    'review-commit' => ['molly:review-commit', ['--workspace' => '@'], 'revision'],
    'project:index' => ['molly:project:index', ['--workspace' => '@'], 'workspace'],
    'project:query' => ['molly:project:query', ['concept' => 'Hello', '--workspace' => '@'], 'freshness.current'],
    'journal' => ['molly:journal', ['--project' => true, '--workspace' => '@'], 'journal_path'],
    'glossary' => ['molly:glossary', ['--workspace' => '@'], 'path'],
    'worker status' => ['molly:worker', ['action' => 'status', '--workspace' => '@'], 'pid_file'],
    'project-init' => ['molly:project-init', ['path' => '@', '--no-composer' => true, '--no-migrate' => true, '--no-graphs' => true], 'project.path'],
]);
