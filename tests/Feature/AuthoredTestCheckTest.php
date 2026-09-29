<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Actions\RecordRedBaseline;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\TarpitReviewer;
use Sifrious\Molly\Models\Task;

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
    $this->get('/counter')->assertSee('Signed in users: 1');
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

it('stops rewriting a test that keeps failing for the same cause at the repair budget', function () {
    config(['molly.max_attempts' => 5, 'molly.repair.per_failure' => 2]);
    $task = authoringTask($this->workspace);
    $renamed = str_replace('counts signed in users', 'counts every signed in user', unmigratedCounterTest());
    ChangeWriter::fake([
        ['summary' => 'Write the counter test.', 'files' => [['path' => 'tests/Feature/CounterTest.php', 'content' => unmigratedCounterTest()]]],
        ['summary' => 'Rename the user test.', 'files' => [['path' => 'tests/Feature/CounterTest.php', 'content' => $renamed]]],
    ])->preventStrayPrompts();
    fakeAuthoringCollaborators(2);

    mollyJson('molly:start', ['task' => $task->id]);
    [, $retry] = mollyJson('molly:retry', ['task' => $task->id]);

    expect($retry['report']['authored_test']['causes'][0]['tests'])->toBe(['it counts every signed in user'])
        ->and($retry['report']['failure_fingerprint']['inputs'])->toBe(['authored_test' => ['database_not_migrated']])
        ->and($retry['next']['command'])->toBe('php artisan molly:lock-test '.$task->id.' --approve')
        ->and($retry['next']['reason'])->toBe('Molly has no attempts left for this test. Edit tests/Feature/CounterTest.php to fix the cause, then lock it.');

    [$exit, $refused] = mollyJson('molly:retry', ['task' => $task->id]);

    expect($exit)->toBe(1)
        ->and($refused['report']['error'])->toStartWith('REPAIR_BUDGET_EXHAUSTED: Molly wrote tests/Feature/CounterTest.php 2 times and each time it could not run for the same cause (database_not_migrated, fingerprint ')
        ->and($refused['report']['error'])->toContain('then lock it with php artisan molly:lock-test '.$task->id.' --approve.')
        ->and($task->runs()->count())->toBe(2);
});

/** A workspace whose composer.lock lists the given packages, holding the RC9 authored test. */
function rc9Workspace(string $workspace, array $packages): array
{
    $fixture = dirname(__DIR__).'/Fixtures/test-authoring/rc9-hello-counter';
    File::put($workspace.'/composer.lock', json_encode(['packages' => [], 'packages-dev' => array_map(fn (string $name): array => ['name' => $name, 'version' => 'v4.1.0'], $packages)]));
    File::copy($fixture.'/HelloCounterTest.rc9.php', $workspace.'/tests/Feature/HelloCounterTest.php');

    return json_decode(File::get($fixture.'/verification.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('classifies the RC9 authored test that calls get() and post() without importing them as a bootstrap error', function () {
    $verification = rc9Workspace($this->workspace, ['pestphp/pest', 'pestphp/pest-plugin-laravel']);

    $check = app(RecordRedBaseline::class)->classify($verification, $this->workspace, 'tests/Feature/HelloCounterTest.php');
    $classified = array_column($check['classified_tests'], 'cause', 'name');

    // RC9 saved this run as missing_behavior, so the lock would have frozen a test that can never pass.
    expect($verification['rc9_classification'])->toBe('missing_behavior')
        ->and($check['classification'])->toBe('bootstrap_error')
        ->and($check['reason'])->toBe('test_helper_not_imported')
        ->and($check['test_broken'])->toBeTrue()
        ->and($check['causes'])->toHaveCount(1)
        ->and($check['causes'][0]['cause'])->toBe('test_helper_not_imported')
        ->and($check['causes'][0]['tests'])->toBe([
            'it 1. Guest sees Hello stranger on home page',
            'it 2. Guest does not see Increment button on home page',
            'it 3. Guest is redirected to login when posting increment action',
            'it 4. Guest can view login page with email, password fields and Login button',
            'it 5. User can login with valid credentials and is redirected to home',
            'it 11. After logout guest sees Hello stranger and no Increment button',
        ])
        ->and($check['causes'][0]['explanation'])->toBe('The tests call get() and post(), Pest plugin helpers that the test file does not import.')
        ->and($check['causes'][0]['guidance'])->toContain('Add `use function Pest\Laravel\{get, post};` after the other `use` statements')
        ->and($check['causes'][0]['guidance'])->toContain('`$this->get(...)`')
        ->and(array_count_values(array_filter($classified)))->toBe(['test_helper_not_imported' => 6])
        ->and(count($classified))->toBe(11)
        // The five tests that ran and failed on assertions still fail for missing behavior.
        ->and(array_keys(array_filter($classified, fn (?string $cause): bool => $cause === null)))->toBe([
            'it 6. Authenticated user sees Hello world on home page',
            'it 7. Authenticated user sees counter at 0 with Increment button',
            'it 8. Authenticated user increments counter and sees count increase',
            'it 9. Authenticated increment action returns 200 and updated count',
            'it 10. Authenticated user can logout and is redirected to login',
        ]);
});

it('tells the model to call the test case when the Pest Laravel plugin is not installed', function () {
    $verification = rc9Workspace($this->workspace, ['pestphp/pest']);

    $check = app(RecordRedBaseline::class)->classify($verification, $this->workspace, 'tests/Feature/HelloCounterTest.php');

    expect($check['classification'])->toBe('bootstrap_error')
        ->and($check['test_broken'])->toBeTrue()
        ->and($check['causes'][0]['cause'])->toBe('test_plugin_missing')
        ->and($check['causes'][0]['explanation'])->toBe('The tests call get() and post(), but pestphp/pest-plugin-laravel is not installed in the workspace.')
        ->and($check['causes'][0]['guidance'])->toContain('Do not import them.')
        ->and($check['causes'][0]['guidance'])->toContain('call the Laravel helpers on the test case, such as `$this->get(...)`');
});

it('refuses to lock an authored test that calls get() without importing it and retries with the import to add', function () {
    // The workspace autoloads the Pest Laravel plugin from Molly's vendor directory; this marks it installed.
    File::ensureDirectoryExists($this->workspace.'/vendor/pestphp/pest-plugin-laravel');
    $task = authoringTask($this->workspace);
    $test = "<?php\n\nit('shows the counter to guests', function () {\n    get('/counter')->assertOk();\n});\n\nit('shows the counter page', function () {\n    \$this->get('/counter')->assertSee('Counter page');\n});\n";
    $imported = str_replace("<?php\n", "<?php\n\nuse function Pest\\Laravel\\get;\n", $test);
    ChangeWriter::fake([
        ['summary' => 'Write the counter test.', 'files' => [['path' => 'tests/Feature/CounterTest.php', 'content' => $test]]],
        ['summary' => 'Import get().', 'files' => [['path' => 'tests/Feature/CounterTest.php', 'content' => $imported]]],
    ])->preventStrayPrompts();
    fakeAuthoringCollaborators(2);

    [, $start] = mollyJson('molly:start', ['task' => $task->id]);
    [$lockExit, $refusal] = mollyJson('molly:lock-test', ['task' => $task->id, '--approve' => true]);

    expect($start['report']['authored_test']['classification'])->toBe('bootstrap_error')
        ->and($start['report']['authored_test']['classified_tests'])->toBe([
            ['name' => 'it shows the counter to guests', 'classification' => 'bootstrap_error', 'cause' => 'test_helper_not_imported'],
            ['name' => 'it shows the counter page', 'classification' => 'missing_behavior', 'cause' => null],
        ])
        ->and($lockExit)->toBe(1)
        ->and($refusal['error'])->toStartWith('AUTHORED_TEST_BROKEN: ')
        ->and($refusal['error'])->toContain('(test_helper_not_imported) Affected tests: it shows the counter to guests.');

    [, $retry] = mollyJson('molly:retry', ['task' => $task->id]);

    ChangeWriter::assertPrompted(function ($prompt): bool {
        $cause = json_decode($prompt->prompt, true, flags: JSON_THROW_ON_ERROR)['previous_attempt']['authored_test']['causes'][0] ?? null;

        return $cause !== null && $cause['cause'] === 'test_helper_not_imported'
            && str_contains($cause['guidance'], 'Add `use function Pest\Laravel\get;`');
    });
    expect($retry['report']['authored_test']['classification'])->toBe('missing_behavior')
        ->and($retry['next']['command'])->toBe('php artisan molly:lock-test '.$task->id.' --approve');
});

it('sends the workspace Livewire layout to the test-authoring run', function () {
    File::put($this->workspace.'/composer.lock', json_encode(['packages' => [['name' => 'livewire/livewire', 'version' => 'v2.12.6']], 'packages-dev' => []]));
    $task = authoringTask($this->workspace);
    ChangeWriter::fake([['summary' => 'Write the counter test.', 'files' => [['path' => 'tests/Feature/CounterTest.php', 'content' => migratedCounterTest()]]]])->preventStrayPrompts();
    fakeAuthoringCollaborators(1);

    mollyJson('molly:start', ['task' => $task->id]);

    ChangeWriter::assertPrompted(fn ($prompt): bool => (json_decode($prompt->prompt, true, flags: JSON_THROW_ON_ERROR)['livewire'] ?? null) === [
        'installed_version' => '2.12.6',
        'class_namespace' => 'App\\Http\\Livewire',
        'class_directory' => 'app/Http/Livewire',
        'view_directory' => 'resources/views/livewire',
    ]);
});
