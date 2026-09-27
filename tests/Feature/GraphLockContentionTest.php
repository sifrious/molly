<?php

use Composer\InstalledVersions;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\QueryKnowledgeGraph;
use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\LaravelVersion;

/*
 * A second PHP process holds the SQLite write lock on the knowledge database.
 * Writers in this process must wait for it; readers must not need it.
 */

beforeEach(function (): void {
    $this->mollyHome = sys_get_temp_dir().'/molly-home-'.Str::uuid();
    File::ensureDirectoryExists($this->mollyHome);
    putenv('MOLLY_HOME='.$this->mollyHome);
    $_ENV['MOLLY_HOME'] = $this->mollyHome;

    $this->project = sys_get_temp_dir().'/molly-lock-'.Str::uuid();
    File::ensureDirectoryExists($this->project);
    File::put($this->project.'/artisan', "#!/usr/bin/env php\n<?php\n");
    File::put($this->project.'/composer.json', json_encode(['require' => ['laravel/framework' => '^12.0']]));
    File::put($this->project.'/composer.lock', json_encode([
        'packages' => [['name' => 'laravel/framework', 'version' => InstalledVersions::getPrettyVersion('laravel/framework')]],
        'packages-dev' => [],
    ]));
    $this->database = $this->project.'/.molly/knowledge.sqlite';
    config()->set('molly.knowledge.database', $this->database);

    // Create the schema and the Laravel graph before any contention starts.
    expect(Artisan::call('molly:graphs-bootstrap', ['path' => $this->project, '--json' => true]))->toBe(0);
});

afterEach(function (): void {
    File::deleteDirectory($this->project);
    File::deleteDirectory($this->mollyHome);
    putenv('MOLLY_HOME');
    unset($_ENV['MOLLY_HOME']);
});

/** Start a PHP process that holds BEGIN IMMEDIATE for $seconds and wait until it has the lock. */
function holdWriteLock(string $database, float $seconds): InvokedProcess
{
    $marker = $database.'.locked';
    @unlink($marker);
    $script = <<<'PHP'
        $pdo = new PDO('sqlite:'.$argv[1]);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('BEGIN IMMEDIATE');
        $pdo->exec("INSERT INTO schema_metadata (key, value) VALUES ('lock_holder', '1') ON CONFLICT(key) DO UPDATE SET value = excluded.value");
        touch($argv[2]);
        usleep((int) ($argv[3] * 1000000));
        $pdo->exec('COMMIT');
        PHP;
    $process = Process::start([PHP_BINARY, '-r', $script, $database, $marker, (string) $seconds]);

    $deadline = microtime(true) + 5;
    while (! is_file($marker)) {
        if (microtime(true) > $deadline || ! $process->running()) {
            throw new RuntimeException('The lock holder did not start: '.$process->latestErrorOutput());
        }
        usleep(10000);
    }

    return $process;
}

it('waits for another process to release the write lock instead of failing at once', function (): void {
    $holder = holdWriteLock($this->database, 1.0);

    $started = microtime(true);
    $exit = Artisan::call('molly:graphs-bootstrap', ['path' => $this->project, '--json' => true]);
    $elapsed = microtime(true) - $started;
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($holder->wait()->successful())->toBeTrue()
        ->and($exit)->toBe(0)
        ->and($payload['status'])->toBe('ok')
        ->and($elapsed)->toBeGreaterThan(0.5);
});

it('indexes the project graph after waiting for the write lock', function (): void {
    $holder = holdWriteLock($this->database, 1.0);

    $started = microtime(true);
    $exit = Artisan::call('molly:project:index', ['--workspace' => $this->project, '--json' => true]);
    $elapsed = microtime(true) - $started;

    expect($holder->wait()->successful())->toBeTrue()
        ->and($exit)->toBe(0)
        ->and(Artisan::output())->not->toContain('database is locked')
        ->and($elapsed)->toBeGreaterThan(0.5);
});

it('answers read-only queries while another process holds the write lock', function (): void {
    $holder = holdWriteLock($this->database, 1.5);

    $started = microtime(true);
    $major = app(LaravelVersion::class)->current();
    $result = app(QueryKnowledgeGraph::class)->handle('Queue');
    $counts = app(Graph::class)->counts('laravel', $major);
    $elapsed = microtime(true) - $started;
    $stillHeld = $holder->running();

    expect($result['nodes'])->not->toBeEmpty()
        ->and($counts['nodes'])->toBeGreaterThan(0)
        ->and($stillHeld)->toBeTrue()
        ->and($elapsed)->toBeLessThan(1.0)
        ->and($holder->wait()->successful())->toBeTrue();
});
