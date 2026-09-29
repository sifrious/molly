<?php

use Illuminate\Support\Facades\File;
use Sifrious\Molly\Classification\ChoiceClassification;
use Sifrious\Molly\Classification\ChoiceClassifier;
use Sifrious\Molly\Tests\Support\FakeChoiceClassifier;
use Sifrious\Molly\Tests\TestCase;
use Symfony\Component\Process\Process;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

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
 * A Laravel application in a temporary directory whose artisan boots tests/Fixtures/queued-runtime.php:
 * a SQLite database queue, fake model and review agents, real Pest checks, and one saved task with
 * a queued start job. The task changes app/Flag.php, and tests/QueuedFlagTest.php checks it.
 * $retryAfter is the queue's retry_after in seconds and $testTimeout is molly.test_timeout. With
 * $slowTest the Pest test sleeps 30 seconds first, so a check is still running when a test signals it. Create hold in the root to make the
 * fake model wait, and remove it to let the model answer.
 */
function queuedExecutionFixture(bool $duplicate = false, bool $fail = false, bool $stop = false, int $retryAfter = 120, bool $slowTest = false, int $testTimeout = 5): string
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
    file_put_contents($root.'/tests/QueuedFlagTest.php', str_replace(['ASSERTION', 'SLOW'], [$assertion, $slowTest ? 'sleep(30);' : ''], <<<'PHPTEST'
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
