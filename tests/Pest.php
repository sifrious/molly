<?php

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process as ProcessFacade;
use Sifrious\Molly\Classification\ChoiceClassification;
use Sifrious\Molly\Classification\ChoiceClassifier;
use Sifrious\Molly\Tests\Support\FakeChoiceClassifier;
use Sifrious\Molly\Tests\TestCase;
use Symfony\Component\Process\Process;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/** Manifest digests of the Ollama models on the acceptance Mac, as the bundled catalogue records them. */
const GPT_OSS_20B_DIGEST = '17052f91a42e97930aa6e28a6c6c06a983e6a58dbb00434885a0cf5313e376f7';
const GPT_OSS_120B_CODE_DIGEST = '4dda6ee4a98ead297f4cdeae389ecdc1963ed4cad90b10c8a11e1cc2a7b0fcaf';
const GPT_OSS_120B_DIGEST = 'a951a23b46a1f6093dafee2ea481d634b4e31ac720a8a16f3f91e04f5a40ecd9';

/**
 * No test may turn the Testbench skeleton at base_path() into a Git repository. Record what
 * the skeleton holds before the suite and fail the run if a repository or bind marker appears.
 */
function testbenchSkeletonGitMarkers(): array
{
    $skeleton = dirname(__DIR__).'/vendor/orchestra/testbench-core/laravel';

    return array_values(array_filter([$skeleton.'/.git', $skeleton.'/README.molly-bind'], 'file_exists'));
}

(static function (): void {
    $before = testbenchSkeletonGitMarkers();
    register_shutdown_function(static function () use ($before): void {
        $gained = array_diff(testbenchSkeletonGitMarkers(), $before);
        if ($gained !== []) {
            fwrite(STDERR, PHP_EOL.'The test run created '.implode(', ', $gained).'. Bind a temporary checkout from commitGitWorkspace() instead of base_path().'.PHP_EOL);
            exit(1);
        }
    });
})();

function writeProtectedTest(string $workspace, string $path = 'tests/GreetingTest.php', string $contents = '<?php it("exists", fn () => expect(true)->toBeTrue());'): string
{
    File::ensureDirectoryExists($workspace.'/'.dirname($path));
    File::put($workspace.'/'.$path, $contents);

    return hash('sha256', $contents);
}

/**
 * Make a test workspace a Git checkout and commit everything in it, as a user does
 * before creating a task. Molly never creates the repository itself. Returns HEAD.
 */
function commitGitWorkspace(string $workspace, string $message = 'Start'): string
{
    $git = static function (array $arguments) use ($workspace): string {
        $process = new Process(['git', '-c', 'user.name=Molly Tests', '-c', 'user.email=tests@example.com', '-c', 'commit.gpgsign=false', '-C', $workspace, ...$arguments]);
        $process->mustRun();

        return trim($process->getOutput());
    };
    if (! file_exists($workspace.'/.git')) {
        $git(['init', '--quiet']);
    }
    $git(['add', '-A']);
    $git(['commit', '--quiet', '--allow-empty', '-m', $message]);

    return $git(['rev-parse', 'HEAD']);
}

/**
 * Open the Jev gate for one test and bind a deterministic classifier in place of Laravel AI.
 * Pass available: false to prove the enabled-but-unavailable state.
 */
function fakeJev(Closure|ChoiceClassification|Throwable|null $answer = null, bool $available = true): FakeChoiceClassifier
{
    config(['molly.jev.enabled' => true, 'ai.providers.typesafe.key' => 'test-key']);
    $fake = new FakeChoiceClassifier($answer, $available);
    app()->instance(ChoiceClassifier::class, $fake);

    return $fake;
}

/** @param list<string> $options */
function jevChoice(string $choice, array $options = ['continue', 'retry', 'stop', 'needs_review'], float $confidence = 0.9, ?string $model = 'jev-latest'): ChoiceClassification
{
    return new ChoiceClassification($choice, array_replace(array_fill_keys($options, 0.0), [$choice => 1.0]), $confidence, $model);
}

/**
 * Build a Testbench Artisan process that runs a Molly command in its own PHP process, the way
 * a shell would. The package manifest goes to a private cache, because in-process tests
 * rewrite Testbench's shared bootstrap cache without this package's service provider.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 */
function testbenchProcess(array $arguments, array $env = [], float $timeout = 60): Process
{
    static $cache = null;
    if ($cache === null) {
        $cache = sys_get_temp_dir().'/molly-testbench-cache-'.getmypid();
        File::ensureDirectoryExists($cache);
        register_shutdown_function(fn () => File::deleteDirectory($cache));
    }
    $package = dirname(__DIR__);

    return new Process(
        [PHP_BINARY, $package.'/vendor/bin/testbench', ...$arguments],
        $package,
        ['APP_PACKAGES_CACHE' => $cache.'/packages.php', 'APP_SERVICES_CACHE' => $cache.'/services.php', ...$env],
        timeout: $timeout,
    );
}

/**
 * A workspace shaped like a fresh Laravel app: Tests\TestCase on Testbench, which
 * sets an application key and loads the workspace's routes/web.php in the web
 * middleware group as Laravel does, and the tests/Pest.php that `pest --init`
 * writes, with RefreshDatabase commented out.
 * Pest reads tests/Pest.php from the directory above the autoloader it loads, so
 * the workspace gets its own autoloader and Pest entry point over Molly's vendor.
 */
function laravelShapedWorkspace(): string
{
    // Pest derives a namespace from the test path, and some system temp paths contain segments PHP rejects.
    $workspace = '/tmp/molly-authored-'.bin2hex(random_bytes(8));
    $vendor = dirname(__DIR__).'/vendor';
    foreach (['app', 'routes', 'tests/Feature', 'tests/Unit', 'vendor/bin', 'vendor/pestphp/pest/bin'] as $directory) {
        File::ensureDirectoryExists($workspace.'/'.$directory);
    }
    File::put($workspace.'/routes/web.php', '<?php');
    File::copy($vendor.'/pestphp/pest/bin/pest', $workspace.'/vendor/pestphp/pest/bin/pest');
    File::put($workspace.'/vendor/bin/pest', "<?php\n\ninclude __DIR__.'/../pestphp/pest/bin/pest';\n");
    File::put($workspace.'/vendor/autoload.php', "<?php\n\n\$loader = require ".var_export($vendor.'/autoload.php', true).";\n\$loader->addPsr4('Tests\\\\', __DIR__.'/../tests/');\n\$loader->addPsr4('App\\\\', __DIR__.'/../app/');\n\nreturn \$loader;\n");
    File::put($workspace.'/phpunit.xml', '<?xml version="1.0" encoding="UTF-8"?><phpunit bootstrap="vendor/autoload.php"><testsuites><testsuite name="Workspace"><directory>tests</directory></testsuite></testsuites></phpunit>');
    File::put($workspace.'/tests/TestCase.php', <<<'PHP'
        <?php

        namespace Tests;

        abstract class TestCase extends \Orchestra\Testbench\TestCase
        {
            protected function defineEnvironment($app): void
            {
                // A fresh application has APP_KEY in .env; the web middleware needs it.
                $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
            }

            protected function defineWebRoutes($router): void
            {
                require __DIR__.'/../routes/web.php';
            }
        }

        PHP);
    File::put($workspace.'/tests/Pest.php', "<?php\n\nuse Illuminate\\Foundation\\Testing\\RefreshDatabase;\nuse Tests\\TestCase;\n\npest()->extend(TestCase::class)\n // ->use(RefreshDatabase::class)\n    ->in('Feature');\n");
    commitGitWorkspace($workspace);

    return $workspace;
}

/** A routes/web.php that adds GET /ready returning exactly {"ready":true}. */
function readyRoute(): string
{
    return File::get(__DIR__.'/Fixtures/docs/ready-route.php');
}

/** @return array{0: int, 1: array<string, mixed>} */
function mollyJson(string $command, array $parameters): array
{
    $exit = Artisan::call($command, [...$parameters, '--json' => true]);

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

/**
 * A Laravel application in a temporary directory whose artisan boots tests/Fixtures/queued-runtime.php:
 * a SQLite database queue, fake model and review agents, real Pest checks, and one saved task with
 * a queued start job. The task changes app/Flag.php, and tests/QueuedFlagTest.php checks it.
 * $retryAfter is the queue's retry_after in seconds and $testTimeout is molly.test_timeout. The Pest
 * test sleeps $testSleep seconds first, so a check can still be running when a test signals it.
 * Create hold in the root to make the fake model wait, and remove it to let the model answer.
 */
function queuedExecutionFixture(bool $duplicate = false, bool $fail = false, bool $stop = false, int $retryAfter = 120, int $testSleep = 0, int $testTimeout = 5): string
{
    $root = sys_get_temp_dir().'/molly-queue-'.bin2hex(random_bytes(8));
    foreach (['app', 'tests', 'storage/logs', 'storage/framework/views', 'bootstrap/cache'] as $path) {
        mkdir($root.'/'.$path, 0700, true);
    }
    symlink(dirname(__DIR__).'/vendor', $root.'/vendor');
    touch($root.'/database.sqlite');
    file_put_contents($root.'/runtime.json', json_encode(['duplicate' => $duplicate, 'stop' => $stop, 'retry_after' => $retryAfter, 'test_timeout' => $testTimeout], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/artisan', '<?php define("MOLLY_QUEUE_TEST_ROOT", __DIR__); require '.var_export(__DIR__.'/Fixtures/queued-runtime.php', true).';');
    file_put_contents($root.'/app/Flag.php', "<?php\nreturn false;\n");
    file_put_contents($root.'/phpunit.xml', '<phpunit bootstrap="vendor/autoload.php" cacheDirectory="storage/phpunit"><testsuites><testsuite name="Flag"><directory>tests</directory></testsuite></testsuites></phpunit>');
    $assertion = $fail ? 'assertFalse' : 'assertTrue';
    file_put_contents($root.'/tests/QueuedFlagTest.php', str_replace(['ASSERTION', 'SLOW'], [$assertion, $testSleep > 0 ? 'sleep('.$testSleep.');' : ''], <<<'PHPTEST'
<?php
final class QueuedFlagTest extends PHPUnit\Framework\TestCase
{
    public function test_flag_value(): void
    {
        SLOW
        $this->ASSERTION(require dirname(__DIR__).'/app/Flag.php');
    }
}
PHPTEST));
    commitGitWorkspace($root);
    try {
        $setup = new Process([PHP_BINARY, $root.'/artisan', 'fixture:setup', '--no-interaction'], $root, timeout: 15);
        $setup->run();
        expect($setup->isSuccessful())->toBeTrue($setup->getOutput().$setup->getErrorOutput());
    } catch (Throwable $exception) {
        File::deleteDirectory($root);
        throw $exception;
    }

    return $root;
}

/** @return array{task: array, runs: list<array>, jobs: int, failed: list<array>} */
function queuedExecutionState(string $root): array
{
    $database = new PDO('sqlite:'.$root.'/database.sqlite');
    $runs = $database->query('select * from molly_runs order by created_at, rowid')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($runs as &$run) {
        $run['report'] = json_decode($run['report'], true, flags: JSON_THROW_ON_ERROR);
    }

    return ['task' => $database->query('select * from molly_tasks')->fetch(PDO::FETCH_ASSOC), 'runs' => $runs, 'jobs' => (int) $database->query('select count(*) from jobs')->fetchColumn(), 'failed' => $database->query('select * from failed_jobs')->fetchAll(PDO::FETCH_ASSOC)];
}

/** @return list<string> "pid command" for each live process whose command line contains $needle. */
function processesMentioning(string $needle): array
{
    $listing = new Process(['ps', '-A', '-o', 'pid=,command=']);
    $listing->run();
    $self = getmypid();

    return array_values(array_filter(array_map('trim', explode("\n", $listing->getOutput())), fn (string $line): bool => $line !== ''
        && str_contains($line, $needle) && (int) $line !== $self && ! str_contains($line, ' ps -A -o ')));
}

/** Wait up to $seconds for $condition, then fail with $message. */
function waitUntil(Closure $condition, string $message, float $seconds = 20): void
{
    $deadline = microtime(true) + $seconds;
    while (! $condition()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out: '.$message);
        }
        usleep(20000);
    }
}

/**
 * Start $command through /bin/sh with job control on, so it runs as its own process group the
 * way a terminal runs a foreground command, and Ctrl-C can be sent with posix_kill(-$pid, SIGINT).
 * Its output goes to $base.out and $base.err, and its exit status to $base.exit.
 *
 * @param  list<string>  $command
 * @return array{shell: Process, pid: int}
 */
function startInOwnProcessGroup(array $command, string $cwd, string $base): array
{
    $script = 'set -m; "$@" >"$0.out" 2>"$0.err" & echo $! >"$0.pid.tmp" && mv "$0.pid.tmp" "$0.pid"; wait $!; echo $? >"$0.exit"';
    $shell = new Process(['/bin/sh', '-c', $script, $base, ...$command], $cwd, timeout: 120);
    $shell->start();
    waitUntil(fn (): bool => is_file($base.'.pid'), 'the command started');

    return ['shell' => $shell, 'pid' => (int) file_get_contents($base.'.pid')];
}

/** @return list<int> the live processes in process group $pgid */
function processGroupMembers(int $pgid): array
{
    $listing = new Process(['ps', '-A', '-o', 'pid=,pgid=']);
    $listing->run();
    $members = [];
    foreach (explode("\n", trim($listing->getOutput())) as $line) {
        [$pid, $group] = array_map(intval(...), preg_split('/\s+/', trim($line)) + [0, 0]);
        if ($group === $pgid && $pid > 0) {
            $members[] = $pid;
        }
    }

    return $members;
}

/**
 * Replay one simulated fixture from tests/Fixtures/hardware through Process::fake and Http::fake.
 * $commands replaces command results by pattern, and $http replaces Ollama API responses by path,
 * where null makes that endpoint refuse the connection. With $fakeHttp false the caller registers
 * the Ollama responses itself, from the returned fixture's `http` entries.
 *
 * @param  array<string, array{exit: int, stdout: string, stderr: string}|Closure>  $commands
 * @param  array<string, array{status: int, json: mixed}|null>  $http
 * @return array<string, mixed> The fixture, with {workspace} resolved.
 */
function replayHardwareFixture(string $name, array $commands = [], array $http = [], bool $fakeHttp = true): array
{
    $fixture = json_decode(File::get(__DIR__.'/Fixtures/hardware/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $workspace = sys_get_temp_dir().'/molly-hardware-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($workspace);
    $fixture['destination'] = str_replace('{workspace}', $workspace, $fixture['destination']);
    $fixture['commands'] = array_replace($fixture['commands'], $commands);
    $fixture['http'] = array_replace(array_is_list($fixture['http'] ?? []) ? [] : $fixture['http'], $http);

    // Start from fresh fakes, so a second replay in the same test replaces the first one's commands and responses.
    ProcessFacade::swap(new ProcessFactory);
    $fakes = [];
    foreach ($fixture['commands'] + ['*' => ['exit' => 127, 'stdout' => '', 'stderr' => 'not simulated']] as $pattern => $result) {
        $fakes[$pattern] = $result instanceof Closure ? $result : ProcessFacade::result($result['stdout'], $result['stderr'], $result['exit']);
    }
    ProcessFacade::preventStrayProcesses();
    ProcessFacade::fake($fakes);

    if ($fakeHttp) {
        Http::swap(new HttpFactory(app('events')));
        Http::preventStrayRequests();
        $responses = [];
        foreach (['/api/version', '/api/tags', '/api/ps'] as $path) {
            $response = $fixture['http'][$path] ?? null;
            $responses['localhost:11434'.$path] = $response === null ? Http::failedConnection() : Http::response($response['json'], $response['status']);
        }
        Http::fake($responses);
    }
    config(['ai.providers.ollama.url' => 'http://localhost:11434']);

    return $fixture;
}

/** A command result for replayHardwareFixture() overrides. */
function commandOutput(string $stdout, int $exit = 0, string $stderr = ''): array
{
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
}

function hardwareFact(array $snapshot, string $path): array
{
    return Arr::get($snapshot['facts'], $path) ?? throw new RuntimeException("Missing fact {$path}");
}
