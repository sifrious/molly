<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\TarpitReviewer;
use Sifrious\Molly\Models\Task;

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
    $vendor = dirname(__DIR__, 2).'/vendor';
    foreach (['app', 'routes', 'tests/Feature', 'tests/Unit', 'vendor/bin', 'vendor/pestphp/pest/bin'] as $directory) {
        File::ensureDirectoryExists($workspace.'/'.$directory);
    }
    File::put($workspace.'/routes/web.php', '<?php');
    File::copy($vendor.'/pestphp/pest/bin/pest', $workspace.'/vendor/pestphp/pest/bin/pest');
    File::put($workspace.'/vendor/bin/pest', "<?php\n\ninclude __DIR__.'/../pestphp/pest/bin/pest';\n");
    File::put($workspace.'/vendor/autoload.php', "<?php\n\n\$loader = require ".var_export($vendor.'/autoload.php', true).";\n\$loader->addPsr4('Tests\\\\', __DIR__.'/../tests/');\n\$loader->addPsr4('App\\\\', __DIR__.'/../app/');\n\nreturn \$loader;\n");
    File::put($workspace.'/phpunit.xml', '<?xml version="1.0" encoding="UTF-8"?><phpunit bootstrap="vendor/autoload.php"><testsuites><testsuite name="Workspace"><directory>tests</directory></testsuite></testsuites></phpunit>');
    File::put($workspace.'/tests/TestCase.php', "<?php\n\nnamespace Tests;\n\nabstract class TestCase extends \\Orchestra\\Testbench\\TestCase {}\n");
    File::put($workspace.'/tests/Pest.php', "<?php\n\npest()->extend(Tests\\TestCase::class)\n    // ->use(Illuminate\\Foundation\\Testing\\RefreshDatabase::class)\n    ->in('Feature');\n");
    commitGitWorkspace($workspace);

    return $workspace;
}

/** An authored Pest file whose second test inserts a user without migrating the database. */
function unmigratedCounterTest(): string
{
    return <<<'PHP'
<?php

use Illuminate\Support\Facades\DB;

it('shows the counter to guests', function () {
    $this->get('/counter')->assertOk();
});

it('counts signed in users', function () {
    DB::table('users')->insert(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    $this->get('/counter')->assertSee('1');
});
PHP;
}

function fakeAuthoringCollaborators(int $reviews): void
{
    $clean = ['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding in the selected files.']), 'findings' => []];
    TarpitReviewer::fake(array_fill(0, $reviews, $clean))->preventStrayPrompts();
    test()->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'skipped', 'probes' => []]);
}

function authoringTask(string $workspace, string $testPath = 'tests/Feature/CounterTest.php'): Task
{
    return app(CreateTask::class)->handle('Guests see the counter, and it counts signed in users.', $workspace, ['app/Counter.php'], $testPath, allowTestEdits: true);
}

/** @return array{0: int, 1: array<string, mixed>} */
function mollyJson(string $command, array $parameters): array
{
    $exit = Artisan::call($command, [...$parameters, '--json' => true]);

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

beforeEach(function () {
    $this->workspace = laravelShapedWorkspace();
    config(['ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434'], 'molly.agent' => 'ollama']);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('classifies an authored test that queries an unmigrated database as a bootstrap error', function () {
    $task = authoringTask($this->workspace);
    ChangeWriter::fake([['summary' => 'Write the counter test.', 'files' => [['path' => 'tests/Feature/CounterTest.php', 'content' => unmigratedCounterTest()]]]])->preventStrayPrompts();
    fakeAuthoringCollaborators(1);

    [$exit, $start] = mollyJson('molly:start', ['task' => $task->id]);
    $check = $start['report']['authored_test'];

    expect($exit)->toBe(1)
        ->and($start['status'])->toBe('failed')
        ->and($check['classification'])->toBe('bootstrap_error')
        ->and($check['reason'])->toBe('database_not_migrated')
        ->and($check['test_broken'])->toBeTrue()
        ->and($check['causes'])->toHaveCount(1)
        ->and($check['causes'][0]['cause'])->toBe('database_not_migrated')
        ->and($check['causes'][0]['tests'])->toBe(['it counts signed in users'])
        ->and($check['causes'][0]['guidance'])->toContain('uses(RefreshDatabase::class);')
        ->and($check['classified_tests'])->toBe([
            ['name' => 'it shows the counter to guests', 'classification' => 'missing_behavior', 'cause' => null],
            ['name' => 'it counts signed in users', 'classification' => 'bootstrap_error', 'cause' => 'database_not_migrated'],
        ]);

    [, $shown] = mollyJson('molly:task', ['task' => $task->id]);
    expect($shown['authored_test']['classification'])->toBe('bootstrap_error');

    Artisan::call('molly:task', ['task' => $task->id]);
    $text = preg_replace('/\s+/', ' ', Artisan::output());
    expect($text)->toContain('Authored test check: bootstrap_error (database_not_migrated)')
        ->and($text)->toContain('neither the test file nor tests/Pest.php applies RefreshDatabase')
        ->and($text)->toContain('Affected tests: it counts signed in users.');

    $failed = app(RecordLifecycleEvent::class)->load($this->workspace)->events($task->id);
    expect(end($failed)->type)->toBe('failed')
        ->and(end($failed)->payload['authored_test'])->toBe([
            'classification' => 'bootstrap_error',
            'reason' => 'database_not_migrated',
            'test_broken' => true,
            'causes' => [['cause' => 'database_not_migrated', 'tests' => ['it counts signed in users']]],
        ]);

    expect(File::get($task->fresh()->journal_status['journal_path']))->toContain('#### Authored test check')
        ->and(File::get($task->fresh()->journal_status['journal_path']))->toContain('database\_not\_migrated');
});

it('classifies an authored test outside the bound TestCase as a bootstrap error', function () {
    $task = authoringTask($this->workspace, 'tests/Unit/CounterTest.php');
    $test = "<?php\n\nit('shows the counter', function () {\n    \$this->get('/counter')->assertOk();\n});\n\nit('shows the counter through the helper', function () {\n    Pest\\Laravel\\get('/counter')->assertOk();\n});\n";
    ChangeWriter::fake([['summary' => 'Write the counter test.', 'files' => [['path' => 'tests/Unit/CounterTest.php', 'content' => $test]]]])->preventStrayPrompts();
    fakeAuthoringCollaborators(1);

    [, $start] = mollyJson('molly:start', ['task' => $task->id]);
    $check = $start['report']['authored_test'];

    expect($check['classification'])->toBe('bootstrap_error')
        ->and($check['reason'])->toBe('test_case_not_bound')
        ->and($check['causes'][0]['tests'])->toBe(['it shows the counter', 'it shows the counter through the helper'])
        ->and($check['causes'][0]['guidance'])->toContain('uses(Tests\TestCase::class);');
});

it('classifies an authored test that fails for unbuilt behavior as missing behavior', function () {
    $task = authoringTask($this->workspace);
    $test = "<?php\n\nuse Illuminate\\Foundation\\Testing\\RefreshDatabase;\nuse Illuminate\\Support\\Facades\\DB;\n\nuses(RefreshDatabase::class);\n\nit('lists counters', function () {\n    DB::table('counters')->count();\n    \$this->get('/counter')->assertOk();\n});\n";
    ChangeWriter::fake([['summary' => 'Write the counter test.', 'files' => [['path' => 'tests/Feature/CounterTest.php', 'content' => $test]]]])->preventStrayPrompts();
    fakeAuthoringCollaborators(1);

    [, $start] = mollyJson('molly:start', ['task' => $task->id]);

    // RefreshDatabase is applied, so a missing table means a migration is still to be written.
    expect($start['report']['authored_test'])->toMatchArray(['classification' => 'missing_behavior', 'reason' => 'tests_failed', 'test_broken' => false, 'causes' => []]);
});

/** The unmigrated test repaired the way the guidance asks. */
function migratedCounterTest(): string
{
    return str_replace("use Illuminate\\Support\\Facades\\DB;\n", "use Illuminate\\Foundation\\Testing\\RefreshDatabase;\nuse Illuminate\\Support\\Facades\\DB;\n\nuses(RefreshDatabase::class);\n", unmigratedCounterTest());
}

it('sends a broken authored test back to Molly with guidance instead of suggesting a lock', function () {
    $task = authoringTask($this->workspace);
    ChangeWriter::fake([
        ['summary' => 'Write the counter test.', 'files' => [['path' => 'tests/Feature/CounterTest.php', 'content' => unmigratedCounterTest()]]],
        ['summary' => 'Apply RefreshDatabase.', 'files' => [['path' => 'tests/Feature/CounterTest.php', 'content' => migratedCounterTest()]]],
    ])->preventStrayPrompts();
    fakeAuthoringCollaborators(2);

    [, $start] = mollyJson('molly:start', ['task' => $task->id]);
    expect($start['next']['command'])->toBe('php artisan molly:retry '.$task->id);

    [, $shown] = mollyJson('molly:task', ['task' => $task->id]);
    expect($shown['next'])->toBe(['command' => 'php artisan molly:retry '.$task->id, 'reason' => 'Have Molly rewrite tests/Feature/CounterTest.php with guidance for this cause.']);

    Artisan::call('molly:task', ['task' => $task->id]);
    $text = preg_replace('/\s+/', ' ', Artisan::output());
    expect($text)->toContain('Run php artisan molly:retry '.$task->id.'.')
        ->and($text)->not->toContain('molly:lock-test');

    [$exit, $retry] = mollyJson('molly:retry', ['task' => $task->id]);

    ChangeWriter::assertPrompted(function ($prompt): bool {
        $payload = json_decode($prompt->prompt, true, flags: JSON_THROW_ON_ERROR);

        return ($payload['read_only_files']['tests/Pest.php'] ?? null) === File::get(test()->workspace.'/tests/Pest.php')
            && ! isset($payload['previous_attempt']);
    });
    ChangeWriter::assertPrompted(function ($prompt): bool {
        $payload = json_decode($prompt->prompt, true, flags: JSON_THROW_ON_ERROR);
        $authored = $payload['previous_attempt']['authored_test'] ?? null;

        return $authored !== null
            && $authored['classification'] === 'bootstrap_error'
            && $authored['causes'][0]['cause'] === 'database_not_migrated'
            && str_contains($authored['causes'][0]['guidance'], 'uses(RefreshDatabase::class);')
            && $authored['causes'][0]['tests'] === ['it counts signed in users'];
    });
    expect(File::get($this->workspace.'/tests/Feature/CounterTest.php'))->toBe(migratedCounterTest())
        ->and($retry['report']['authored_test']['classification'])->toBe('missing_behavior')
        ->and($retry['next']['command'])->toBe('php artisan molly:lock-test '.$task->id.' --approve');

    [$lockExit, $lock] = mollyJson('molly:lock-test', ['task' => $task->id, '--approve' => true]);
    expect($lockExit)->toBe(0)
        ->and($lock['locked'])->toBeTrue()
        ->and($lock['red_baseline']['classification'])->toBe('missing_behavior');
});

it('gives the model Laravel guidance for an unbound TestCase', function () {
    $task = authoringTask($this->workspace, 'tests/Unit/CounterTest.php');
    $test = "<?php\n\nit('shows the counter', function () {\n    \$this->get('/counter')->assertOk();\n});\n";
    ChangeWriter::fake([
        ['summary' => 'Write the counter test.', 'files' => [['path' => 'tests/Unit/CounterTest.php', 'content' => $test]]],
        ['summary' => 'Bind the TestCase.', 'files' => [['path' => 'tests/Unit/CounterTest.php', 'content' => str_replace("<?php\n", "<?php\n\nuses(Tests\\TestCase::class);\n", $test)]]],
    ])->preventStrayPrompts();
    fakeAuthoringCollaborators(2);

    mollyJson('molly:start', ['task' => $task->id]);
    [, $retry] = mollyJson('molly:retry', ['task' => $task->id]);

    ChangeWriter::assertPrompted(fn ($prompt): bool => str_contains(json_decode($prompt->prompt, true)['previous_attempt']['authored_test']['causes'][0]['guidance'] ?? '', 'uses(Tests\TestCase::class);'));
    expect($retry['report']['authored_test']['classification'])->toBe('missing_behavior');
});

it('refuses to lock a broken authored test and names the cause and the retry', function () {
    $task = authoringTask($this->workspace);
    ChangeWriter::fake([['summary' => 'Write the counter test.', 'files' => [['path' => 'tests/Feature/CounterTest.php', 'content' => unmigratedCounterTest()]]]])->preventStrayPrompts();
    fakeAuthoringCollaborators(1);
    mollyJson('molly:start', ['task' => $task->id]);
    $before = $task->fresh()->only(['allow_test_edits', 'test_digest', 'paths', 'status', 'source']);

    [$exit, $refusal] = mollyJson('molly:lock-test', ['task' => $task->id, '--approve' => true]);

    expect($exit)->toBe(1)
        ->and($refusal['status'])->toBe('error')
        ->and($refusal['error'])->toStartWith('AUTHORED_TEST_BROKEN: Molly did not lock tests/Feature/CounterTest.php because it cannot run.')
        ->and($refusal['error'])->toContain('(database_not_migrated) Affected tests: it counts signed in users.')
        ->and($refusal['error'])->toContain('Run php artisan molly:retry '.$task->id.'.')
        ->and($refusal['authored_test']['classification'])->toBe('bootstrap_error')
        ->and($refusal['authored_test']['causes'][0]['cause'])->toBe('database_not_migrated')
        ->and($refusal['next']['command'])->toBe('php artisan molly:retry '.$task->id)
        ->and($task->fresh()->only(['allow_test_edits', 'test_digest', 'paths', 'status', 'source']))->toBe($before);

    // A person may repair the test instead; the next lock checks it again.
    File::put($this->workspace.'/tests/Feature/CounterTest.php', migratedCounterTest());
    [$lockExit, $lock] = mollyJson('molly:lock-test', ['task' => $task->id, '--approve' => true]);

    expect($lockExit)->toBe(0)
        ->and($lock['locked'])->toBeTrue()
        ->and($lock['red_baseline']['classification'])->toBe('missing_behavior')
        ->and($task->fresh()->redBaselineError())->toBeNull();
});
