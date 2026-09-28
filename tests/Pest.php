<?php

use Illuminate\Support\Facades\Artisan;
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
 * A workspace shaped like a fresh Laravel app: Tests\TestCase on Testbench, and
 * the tests/Pest.php that `pest --init` writes, with RefreshDatabase commented out.
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
    File::put($workspace.'/tests/TestCase.php', "<?php\n\nnamespace Tests;\n\nabstract class TestCase extends \\Orchestra\\Testbench\\TestCase {}\n");
    File::put($workspace.'/tests/Pest.php', "<?php\n\nuse Illuminate\\Foundation\\Testing\\RefreshDatabase;\nuse Tests\\TestCase;\n\npest()->extend(TestCase::class)\n // ->use(RefreshDatabase::class)\n    ->in('Feature');\n");
    commitGitWorkspace($workspace);

    return $workspace;
}

/** @return array{0: int, 1: array<string, mixed>} */
function mollyJson(string $command, array $parameters): array
{
    $exit = Artisan::call($command, [...$parameters, '--json' => true]);

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}
