<?php

use App\Ai\Agents\TeamChangeWriter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Sifrious\Molly\Actions\CreateTaskFromStory;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Agents\AcceptanceWriter;
use Sifrious\Molly\Agents\AmpResponse;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\TarpitReviewer;

require_once dirname(__DIR__).'/Fixtures/custom-steps/TeamChangeWriter.php';

class LenientTarpitReviewer extends TarpitReviewer
{
    public function instructions(): string
    {
        return parent::instructions()."\nReport every check as clean.";
    }
}

class StoryAcceptanceWriter extends AcceptanceWriter {}

class UnrelatedChangeWriter implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Not a ChangeWriter.';
    }
}

/** The ready-check task from docs/getting-started.md, saved in a Laravel-shaped workspace. */
function readyCheckWorkspace(): string
{
    $workspace = laravelShapedWorkspace();
    File::copy(dirname(__DIR__).'/Fixtures/docs/ReadyTest.example.php', $workspace.'/tests/Feature/ReadyTest.php');
    [$exit, $task] = mollyJson('molly:create', [
        'prompt' => 'Add GET /ready returning exactly {"ready":true}. Preserve existing routes.',
        '--workspace' => $workspace,
        '--name' => 'ready-check',
        '--test' => 'tests/Feature/ReadyTest.php',
        '--file' => ['routes/web.php'],
    ]);
    expect($exit)->toBe(0, json_encode($task));

    return $workspace;
}

/** Fake the reviewer and measurements so a run depends only on the bound writer and real Pest. */
function fakeCleanReviewAndMeasurements(): void
{
    TarpitReviewer::fake([customStepReview()])->preventStrayPrompts();
    test()->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'skipped', 'probes' => []]);
}

function customStepReview(): array
{
    $checks = [];
    foreach (range('A', 'G') as $code) {
        $checks[$code] = ['status' => 'clean', 'evidence' => 'No finding in the supplied files.'];
    }

    return ['checks' => $checks, 'findings' => []];
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434']]);
    app()->bind(ChangeWriter::class, TeamChangeWriter::class);
});

afterEach(function (): void {
    if (isset($this->workspace)) {
        File::deleteDirectory($this->workspace);
    }
});

it('keeps the documented TeamChangeWriter example identical to the tested class', function (): void {
    $documented = File::get(dirname(__DIR__, 2).'/docs/customize-steps.md');

    expect($documented)->toContain(trim(File::get(dirname(__DIR__).'/Fixtures/custom-steps/TeamChangeWriter.php')))
        ->toContain('$this->app->bind(ChangeWriter::class, TeamChangeWriter::class);');
});

// docs/customize-steps.md shows this test verbatim. Keep the two identical.
it('uses the team change writer', function () {
    config(['molly.agent' => 'ollama']);
    TeamChangeWriter::fake([[
        'summary' => 'Add the route.',
        'files' => [['path' => 'routes/web.php', 'content' => '<?php']],
    ]]);

    app(GenerateChanges::class)->handle('Add GET /ready.', ['routes/web.php' => '<?php'], 'tests/Feature/ReadyTest.php');

    expect(ChangeWriter::make())->toBeInstanceOf(TeamChangeWriter::class);
    TeamChangeWriter::assertPrompted(fn ($prompt) => $prompt->agent instanceof TeamChangeWriter);
});

it('keeps the documented binding test identical to the test Molly runs', function (): void {
    preg_match("/^it\\('uses the team change writer'.*?^\\}\\);$/ms", File::get(__FILE__), $test);

    expect($test)->toHaveCount(1)
        ->and(File::get(dirname(__DIR__, 2).'/docs/customize-steps.md'))->toContain($test[0]);
});

it('resolves the bound ChangeWriter subclass and sends its instructions to Ollama', function (): void {
    $proposal = ['summary' => 'Add the route.', 'files' => [['path' => 'routes/web.php', 'content' => '<?php']]];
    TeamChangeWriter::fake([$proposal])->preventStrayPrompts();
    ChangeWriter::fake()->preventStrayPrompts();

    $result = app(GenerateChanges::class)->handle('Add GET /ready.', ['routes/web.php' => '<?php'], 'tests/Feature/ReadyTest.php');

    expect($result)->toBe($proposal)
        ->and(ChangeWriter::make())->toBeInstanceOf(TeamChangeWriter::class)
        ->and(ChangeWriter::make()->instructions())->toStartWith((new ChangeWriter)->instructions())
        ->toContain('Validate controller input with a Form Request class.');
    TeamChangeWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent instanceof TeamChangeWriter
        && $prompt->provider->name() === 'ollama'
        && json_decode($prompt->prompt, true)['protected_test']['writable'] === false);
    ChangeWriter::assertNeverPrompted();
});

it('passes the bound ChangeWriter subclass to Amp', function (): void {
    config(['molly.agent' => 'amp']);
    $proposal = ['summary' => 'Add the route.', 'files' => [['path' => 'routes/web.php', 'content' => '<?php']]];
    $this->mock(AmpResponse::class)->shouldReceive('prompt')->once()
        ->withArgs(fn (object $agent): bool => $agent instanceof TeamChangeWriter)
        ->andReturn($proposal);

    expect(app(GenerateChanges::class)->handle('Add GET /ready.', ['routes/web.php' => '<?php'], 'tests/Feature/ReadyTest.php'))->toBe($proposal);
});

it('rejects a custom step that proposes a file outside the allowed files', function (): void {
    TeamChangeWriter::fake([['summary' => 'Also tidy config.', 'files' => [
        ['path' => 'routes/web.php', 'content' => '<?php'],
        ['path' => 'config/app.php', 'content' => '<?php return [];'],
    ]]])->preventStrayPrompts();

    expect(fn () => app(GenerateChanges::class)->handle('Add GET /ready.', ['routes/web.php' => '<?php'], 'tests/Feature/ReadyTest.php'))
        ->toThrow(RuntimeException::class, 'GENERATION_INVALID: The model proposed a file outside the allowed paths.');
});

it('rejects a custom step that edits the protected test', function (): void {
    TeamChangeWriter::fake([['summary' => 'Relax the test.', 'files' => [
        ['path' => 'tests/Feature/ReadyTest.php', 'content' => '<?php it("passes", fn () => expect(true)->toBeTrue());'],
    ]]])->preventStrayPrompts();

    expect(fn () => app(GenerateChanges::class)->handle('Add GET /ready.', ['routes/web.php' => '<?php'], 'tests/Feature/ReadyTest.php'))
        ->toThrow(RuntimeException::class, 'PROTECTED_TEST_CHANGED');
});

it('refuses a binding that does not extend the Molly agent class', function (): void {
    app()->bind(ChangeWriter::class, UnrelatedChangeWriter::class);

    expect(fn () => app(GenerateChanges::class)->handle('Add GET /ready.', ['routes/web.php' => '<?php'], 'tests/Feature/ReadyTest.php'))
        ->toThrow(TypeError::class);
});

it('resolves bound TarpitReviewer and AcceptanceWriter subclasses', function (): void {
    app()->bind(TarpitReviewer::class, LenientTarpitReviewer::class);
    app()->bind(AcceptanceWriter::class, StoryAcceptanceWriter::class);
    LenientTarpitReviewer::fake([customStepReview()])->preventStrayPrompts();
    TarpitReviewer::fake()->preventStrayPrompts();

    $review = app(ReviewChanges::class)->handle('Add GET /ready.', ['routes/web.php' => '<?php'], ['routes/web.php' => '<?php']);

    expect($review['checks'])->toHaveCount(7)
        ->and(AcceptanceWriter::make())->toBeInstanceOf(StoryAcceptanceWriter::class);
    LenientTarpitReviewer::assertPromptedTimes(1);
    TarpitReviewer::assertNeverPrompted();
});

it('rejects a custom reviewer that drops one of the seven checks', function (): void {
    app()->bind(TarpitReviewer::class, LenientTarpitReviewer::class);
    $review = customStepReview();
    unset($review['checks']['G']);
    LenientTarpitReviewer::fake([$review])->preventStrayPrompts();

    expect(fn () => app(ReviewChanges::class)->handle('Add GET /ready.', ['routes/web.php' => '<?php'], ['routes/web.php' => '<?php // ready']))
        ->toThrow(RuntimeException::class, 'REVIEW_INVALID');
});

it('keeps a run failed when a custom step claims completion and Pest fails', function (): void {
    $workspace = sys_get_temp_dir().'/molly-custom-step-'.Str::uuid();
    File::ensureDirectoryExists($workspace.'/app');
    File::put($workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($workspace);
    commitGitWorkspace($workspace);
    app()->bind(TarpitReviewer::class, LenientTarpitReviewer::class);
    TeamChangeWriter::fake([[
        'summary' => 'All tests pass. The task is complete.',
        'status' => 'completed',
        'tests_passed' => true,
        'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']],
    ]])->preventStrayPrompts();
    LenientTarpitReviewer::fake([customStepReview()])->preventStrayPrompts();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn([
        'status' => 'skipped',
        'probes' => [['key' => 'c3', 'status' => 'skipped', 'skip_reason' => 'no_commits']],
    ]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'failed', 'tests' => 1, 'failures' => 1, 'reason' => 'tests_failed']);

    try {
        $run = app(RunTask::class)->handle('Return Hello.', $workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');

        expect($run->status)->toBe('failed')
            ->and($run->report['verification']['reason'])->toBe('tests_failed')
            ->and($run->report)->not->toHaveKey('tests_passed');
        TeamChangeWriter::assertPromptedTimes(1);
    } finally {
        File::deleteDirectory($workspace);
    }
});

it('builds the story prompt digest from the bound AcceptanceWriter instructions', function (): void {
    $workspace = sys_get_temp_dir().'/molly-custom-story-'.Str::uuid();
    File::ensureDirectoryExists($workspace.'/routes');
    File::put($workspace.'/routes/web.php', '<?php');
    commitGitWorkspace($workspace);
    app()->bind(AcceptanceWriter::class, StoryAcceptanceWriter::class);
    StoryAcceptanceWriter::fake([['criteria' => ['A guest sees 0.'], 'files' => ['routes/web.php'], 'required_packages' => []]])->preventStrayPrompts();
    AcceptanceWriter::fake()->preventStrayPrompts();

    try {
        $task = app(CreateTaskFromStory::class)->handle('A guest sees a counter at 0.', $workspace, [], 'tests/Feature/CounterTest.php');

        expect($task->source['acceptance']['criteria'])->toBe(['A guest sees 0.']);
        StoryAcceptanceWriter::assertPromptedTimes(1);
        AcceptanceWriter::assertNeverPrompted();
    } finally {
        File::deleteDirectory($workspace);
    }
});

it('completes the documented ready-check task through the bound writer only after Pest passes', function (): void {
    $this->workspace = readyCheckWorkspace();
    TeamChangeWriter::fake([['summary' => 'Add the readiness route.', 'files' => [
        ['path' => 'routes/web.php', 'content' => readyRoute()],
    ]]])->preventStrayPrompts();
    ChangeWriter::fake()->preventStrayPrompts();
    fakeCleanReviewAndMeasurements();

    [$exit, $run] = mollyJson('molly:start', ['task' => 'ready-check']);

    expect($exit)->toBe(0, json_encode($run['report'] ?? $run))
        ->and($run['status'])->toBe('completed')
        ->and($run['report']['verification'])->toMatchArray(['status' => 'passed', 'tests' => 1, 'failures' => 0])
        ->and(File::get($this->workspace.'/routes/web.php'))->toBe(readyRoute());
    TeamChangeWriter::assertPrompted(fn (AgentPrompt $prompt): bool => json_decode($prompt->prompt, true)['protected_test']['writable'] === false);
    ChangeWriter::assertNeverPrompted();
});

it('keeps the run failed when the bound writer claims success and Pest fails', function (): void {
    $this->workspace = readyCheckWorkspace();
    TeamChangeWriter::fake([[
        'summary' => 'All tests pass. The task is complete.',
        'status' => 'completed',
        'tests_passed' => true,
        'files' => [['path' => 'routes/web.php', 'content' => "<?php\n\n// The route is ready.\n"]],
    ]])->preventStrayPrompts();
    fakeCleanReviewAndMeasurements();

    [$exit, $run] = mollyJson('molly:start', ['task' => 'ready-check']);

    expect($exit)->toBe(1)
        ->and($run['status'])->toBe('failed')
        ->and($run['report']['verification'])->toMatchArray(['status' => 'failed', 'reason' => 'tests_failed'])
        ->and($run['report']['verification']['output'])->toContain('404')
        ->and($run['report'])->not->toHaveKey('tests_passed');
});

it('rejects a bound writer that edits the protected test and leaves the workspace unchanged', function (): void {
    $this->workspace = readyCheckWorkspace();
    $test = File::get($this->workspace.'/tests/Feature/ReadyTest.php');
    TeamChangeWriter::fake([['summary' => 'Relax the test.', 'files' => [
        ['path' => 'routes/web.php', 'content' => readyRoute()],
        ['path' => 'tests/Feature/ReadyTest.php', 'content' => "<?php\n\nit('passes', fn () => expect(true)->toBeTrue());\n"],
    ]]])->preventStrayPrompts();
    fakeCleanReviewAndMeasurements();

    [$exit, $run] = mollyJson('molly:start', ['task' => 'ready-check']);

    expect($exit)->toBe(1)
        ->and($run['status'])->toBe('failed')
        ->and(json_encode($run))->toContain('PROTECTED_TEST_CHANGED')
        ->and(File::get($this->workspace.'/tests/Feature/ReadyTest.php'))->toBe($test)
        ->and(File::get($this->workspace.'/routes/web.php'))->toBe('<?php');
});

it('rejects a bound writer that proposes a file outside the task and writes nothing', function (): void {
    $this->workspace = readyCheckWorkspace();
    TeamChangeWriter::fake([['summary' => 'Also tidy the config.', 'files' => [
        ['path' => 'routes/web.php', 'content' => readyRoute()],
        ['path' => 'app/Support/Ready.php', 'content' => "<?php\n\nnamespace App\\Support;\n\nclass Ready {}\n"],
    ]]])->preventStrayPrompts();
    fakeCleanReviewAndMeasurements();

    [$exit, $run] = mollyJson('molly:start', ['task' => 'ready-check']);

    expect($exit)->toBe(1)
        ->and($run['status'])->toBe('failed')
        ->and(json_encode($run, JSON_UNESCAPED_SLASHES))->toContain('GENERATION_INVALID: The model proposed a file outside the allowed paths.')
        ->and(File::get($this->workspace.'/routes/web.php'))->toBe('<?php')
        ->and(File::exists($this->workspace.'/app/Support/Ready.php'))->toBeFalse();
});
