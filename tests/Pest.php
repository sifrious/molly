<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Sifrious\Molly\Classification\ChoiceClassification;
use Sifrious\Molly\Classification\ChoiceClassifier;
use Sifrious\Molly\Tests\Support\FakeChoiceClassifier;
use Sifrious\Molly\Tests\TestCase;
use Symfony\Component\Process\Process;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/*
 * GitHub's runners have no global Git identity, so a test commit that relies on one passes on a
 * developer's machine and fails in CI. Point Git at a private global config with a test identity
 * for this process and every process it starts, so each commit a test makes, and each script that
 * checks for an identity, sees the same settings everywhere and none of the developer's own.
 */
(static function (): void {
    $config = tempnam(sys_get_temp_dir(), 'molly-gitconfig-');
    file_put_contents($config, "[user]\n\tname = Molly Tests\n\temail = tests@example.com\n[commit]\n\tgpgsign = false\n[tag]\n\tgpgsign = false\n");
    putenv('GIT_CONFIG_GLOBAL='.$config);
    $_ENV['GIT_CONFIG_GLOBAL'] = $_SERVER['GIT_CONFIG_GLOBAL'] = $config;
    $owner = getmypid();
    register_shutdown_function(static function () use ($config, $owner): void {
        if (getmypid() === $owner) {
            @unlink($config);
        }
    });
})();

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
 * Start $command through /bin/sh as its own process group, the way a terminal runs a foreground
 * command, so Ctrl-C can be sent with posix_kill(-$pid, SIGINT). The shell uses setsid where it
 * exists, because dash, the /bin/sh of Linux runners, turns job control off without a terminal,
 * and job control elsewhere, as on macOS. Its output goes to $base.out and $base.err, and its
 * exit status to $base.exit.
 *
 * @param  list<string>  $command
 * @return array{shell: Process, pid: int}
 */
function startInOwnProcessGroup(array $command, string $cwd, string $base): array
{
    $script = 'if command -v setsid >/dev/null 2>&1; then detach=setsid; else detach=; set -m; fi; $detach "$@" >"$0.out" 2>"$0.err" & echo $! >"$0.pid.tmp" && mv "$0.pid.tmp" "$0.pid"; wait $!; echo $? >"$0.exit"';
    $shell = new Process(['/bin/sh', '-c', $script, $base, ...$command], $cwd, timeout: 120);
    $shell->start();
    waitUntil(fn (): bool => is_file($base.'.pid'), 'the command started');
    $pid = (int) file_get_contents($base.'.pid');
    // setsid moves the command into its group after the shell forks it.
    waitUntil(fn (): bool => posix_getpgid($pid) === $pid || is_file($base.'.exit'), 'the command leads its own process group');

    return ['shell' => $shell, 'pid' => $pid];
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
