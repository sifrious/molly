<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Sifrious\Molly\Actions\CreatePlan;
use Sifrious\Molly\Actions\SuggestPlanReview;
use Sifrious\Molly\Models\Plan;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\PlanningGuide;

beforeEach(function (): void {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'session.driver' => 'array', 'molly.ui.enabled' => true]);
    Http::preventStrayRequests();
    $this->planningWorkspace = sys_get_temp_dir().'/molly-planning-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->planningWorkspace.'/tests');
    File::ensureDirectoryExists($this->planningWorkspace.'/app');
    File::put($this->planningWorkspace.'/app/Greeting.php', '<?php');
    writeProtectedTest($this->planningWorkspace);
});

afterEach(function (): void {
    File::deleteDirectory($this->planningWorkspace);
});

it('saves a guided plan through labeled server forms without executing work', function (): void {
    Queue::fake();
    $this->get('/molly/plans/create')->assertOk()
        ->assertSee('name="_token"', false)->assertSee('for="description"', false)
        ->assertSee('type="radio"', false)->assertSee('value="guided" checked', false);

    $response = $this->post('/molly/plans', ['description' => 'Build a household task planner.', 'review_mode' => 'guided']);

    $plan = Plan::sole();
    $response->assertRedirect(route('molly.plans.show', $plan->id));
    expect($plan->review_mode)->toBe('guided')->and($plan->answers)->toBe([])
        ->and(Task::count())->toBe(0)->and(Run::count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('walks through one saved question at a time and escapes answers and descriptions', function (): void {
    $plan = app(CreatePlan::class)->handle('Build <script>alert(1)</script> with Laravel.');
    $steps = app(PlanningGuide::class)->steps();
    $sources = app(PlanningGuide::class)->sourcesFor($plan->description);
    $page = $this->get('/molly/plans/'.$plan->id)->assertOk()
        ->assertSee($steps[0]['question'])->assertDontSee($steps[1]['question'])
        ->assertSee($plan->description)->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('Save task from plan');
    foreach ($sources as $source) {
        $page->assertSee($source['title'])->assertSee($source['revision'])->assertSee($source['url']);
    }

    foreach ($steps as $index => $step) {
        $answer = 'Use the existing Laravel app. <script>answer'.$index.'()</script>';
        $this->post('/molly/plans/'.$plan->id.'/answers', ['step' => $step['id'], 'answer' => $answer])
            ->assertRedirect(route('molly.plans.show', $plan->id));
        $page = $this->get('/molly/plans/'.$plan->id)->assertOk()->assertSee($answer)
            ->assertDontSee('<script>answer'.$index.'()</script>', false);
        expect($plan->fresh()->answers[$step['id']])->toBe($answer);
        if (isset($steps[$index + 1])) {
            $page->assertSee($steps[$index + 1]['question'])->assertDontSee('Save task from plan');
        }
    }

    $page->assertSee('Questions complete')->assertSee('Save task from plan');
    expect($plan->fresh()->completed())->toBeTrue()->and(Task::count())->toBe(0);
});

it('preserves saved answers when an out of order web submission is rejected', function (): void {
    $plan = app(CreatePlan::class)->handle('Build a household task planner.');
    $steps = app(PlanningGuide::class)->steps();

    $this->from('/molly/plans/'.$plan->id)->post('/molly/plans/'.$plan->id.'/answers', ['step' => $steps[1]['id'], 'answer' => 'Skip ahead.'])
        ->assertRedirect('/molly/plans/'.$plan->id)->assertSessionHasErrors('answer');

    expect($plan->fresh()->answers)->toBe([]);
});

it('creates a pending task after an explicit skipped review without starting execution', function (): void {
    Queue::fake();
    $plan = app(CreatePlan::class)->handle('Build a household task planner.', false);
    $this->get('/molly/plans/'.$plan->id)->assertOk()->assertSee('Skipped by choice')->assertSee('Save task from plan');

    $response = $this->post('/molly/plans/'.$plan->id.'/tasks', [
        'prompt' => 'Add a greeting with a test.', 'workspace' => $this->planningWorkspace,
        'paths' => "app/Greeting.php\r\ntests/GreetingTest.php\r\n", 'test_path' => 'tests/GreetingTest.php',
    ]);

    $task = Task::sole();
    $response->assertRedirect(route('molly.tasks.show', $task->id));
    expect($task->status)->toBe('pending')
        ->and($task->paths)->toBe(['app/Greeting.php'])
        ->and(Run::count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('rejects task creation before guided answers are complete', function (): void {
    $plan = app(CreatePlan::class)->handle('Build a household task planner.');

    $this->post('/molly/plans/'.$plan->id.'/tasks', [
        'prompt' => 'Add a greeting.', 'workspace' => $this->planningWorkspace,
        'paths' => 'tests/GreetingTest.php', 'test_path' => 'tests/GreetingTest.php', 'allow_test_edits' => '1',
    ])->assertSessionHasErrors('task');

    expect(Task::count())->toBe(0)->and($plan->fresh()->answers)->toBe([]);
});

it('requires a description for unattended planning without saving an empty plan', function (): void {
    $exit = Artisan::call('molly:plan', ['--json' => true, '--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['status' => 'error'])
        ->and(Plan::count())->toBe(0);
});

it('creates JSON plans without prompts and returns the next question and source provenance', function (bool $skip): void {
    $exit = Artisan::call('molly:plan', ['description' => 'Build a Laravel household planner.', '--json' => true, '--skip-review' => $skip]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $plan = Plan::sole();
    $saved = $plan->toArray();
    ksort($saved);
    ksort($result['plan']);
    expect($exit)->toBe(0)->and($result['id'])->toBe($plan->id)
        ->and($result['status'])->toBe($skip ? 'ready' : 'draft')
        ->and($result['plan'])->toBe($saved)
        ->and($result['next_step'])->toBe($plan->nextStep())
        ->and($result['sources'])->toBe(app(PlanningGuide::class)->sourcesFor($plan->description, []));
    Http::assertNothingSent();
})->with([false, true]);

it('resumes a saved plan with a machine supplied answer and rejects replacement descriptions', function (): void {
    $plan = app(CreatePlan::class)->handle('Build a household task planner.');
    $first = $plan->nextStep();
    $exit = Artisan::call('molly:plan', ['--resume' => $plan->id, '--step' => $first['id'], '--answer' => 'Start with one routine.', '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)->and($plan->fresh()->answers)->toBe([$first['id'] => 'Start with one routine.'])
        ->and($result['next_step']['id'])->not->toBe($first['id']);

    $exit = Artisan::call('molly:plan', ['description' => 'Replace the project.', '--resume' => $plan->id, '--json' => true]);

    expect($exit)->toBe(1)->and($plan->fresh()->description)->toBe('Build a household task planner.')
        ->and(Plan::count())->toBe(1);
});

it('asks whether to guide an interactive plan and saves each answer', function (): void {
    $command = $this->artisan('molly:plan')
        ->expectsQuestion('What would you like to build?', 'Build a household task planner.')
        ->expectsQuestion('Walk through the Tarpit review?', true);
    foreach (app(PlanningGuide::class)->steps() as $step) {
        $command->expectsQuestion($step['question'], 'Use the existing routine for '.$step['id'].'.');
    }

    $command->assertSuccessful()->run();

    expect(Plan::sole()->completed())->toBeTrue()->and(Run::count())->toBe(0);
    Http::assertNothingSent();
});

it('honors an interactive choice to skip questions', function (): void {
    $this->artisan('molly:plan', ['description' => 'Build a household task planner.'])
        ->expectsQuestion('Walk through the Tarpit review?', false)
        ->assertSuccessful();

    expect(Plan::sole()->review_mode)->toBe('skip')->and(Plan::sole()->answers)->toBe([]);
});

it('rejects incomplete machine answer options before saving a plan', function (array $options): void {
    $exit = Artisan::call('molly:plan', ['description' => 'Build a planner.', '--json' => true, ...$options]);

    expect($exit)->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'])->toContain('--resume, --step, and --answer')
        ->and(Plan::count())->toBe(0);
})->with([
    'step without answer' => [['--step' => 'outcome']],
    'answer without step' => [['--answer' => 'One routine.']],
    'answer without saved plan' => [['--step' => 'outcome', '--answer' => 'One routine.']],
]);

it('returns byte-limit feedback when a multibyte web description exceeds the planning budget', function (): void {
    $this->from('/molly/plans/create')->post('/molly/plans', ['description' => str_repeat('é', 751), 'review_mode' => 'guided'])
        ->assertRedirect('/molly/plans/create')->assertSessionHasErrors('description');

    expect(Plan::count())->toBe(0);
});

it('keeps planning behind the local UI guard and returns not found for unknown plans', function (): void {
    $this->get('/molly/plans/missing')->assertNotFound();
    config(['molly.ui.enabled' => false]);
    $this->get('/molly/plans/create')->assertNotFound();
    $this->post('/molly/plans', ['description' => 'Build a planner.', 'review_mode' => 'skip'])->assertNotFound();
    expect(Plan::count())->toBe(0);
});

it('renders every bundled citation and graph connection without JavaScript', function (): void {
    $guide = app(PlanningGuide::class);

    $page = $this->get('/molly/guide')->assertOk()->assertSee('Question order and source references')
        ->assertSee('id="source:nativephp-desktop"', false)->assertSee('id="source:nativephp-mobile"', false);

    foreach ($guide->graph()['sources'] as $source) {
        $page->assertSee($source['title'])->assertSee($source['revision'])->assertSee($source['url']);
    }
    foreach ($guide->graph()['edges'] as $edge) {
        $page->assertSee('href="#'.$edge['from'].'"', false)->assertSee('href="#'.$edge['to'].'"', false);
    }
});

it('lists saved plans and links back to their review', function (): void {
    $plan = app(CreatePlan::class)->handle('Resume this project tomorrow.');
    $this->get('/molly/plans')->assertOk()->assertSee('Resume this project tomorrow.')
        ->assertSee(route('molly.plans.show', $plan->id), false)->assertSee('Review in progress');
    $this->get('/molly/plans/'.$plan->id)->assertOk()->assertSee(route('molly.plans.index'), false);
});

it('requires an open, capable, and configured Jev gate before exposing or requesting a suggestion', function (Closure $arrange): void {
    $arrange();
    $plan = app(CreatePlan::class)->handle('Review the household planner.');
    $suggest = Mockery::mock(SuggestPlanReview::class);
    $suggest->shouldNotReceive('handle');
    $this->app->instance(SuggestPlanReview::class, $suggest);
    $this->get('/molly/plans/'.$plan->id)->assertOk()->assertDontSee('Ask for a Tarpit suggestion')->assertSee('Jev classification is off or not configured');
    $this->post('/molly/plans/'.$plan->id.'/suggest')->assertForbidden();
})->with([
    'disabled by default' => [fn () => expect(config('molly.jev.enabled'))->toBeFalse()],
    'enabled without capability' => [fn () => fakeJev(available: false)],
    'enabled without credential' => [fn () => tap(fakeJev(), fn () => config(['ai.providers.typesafe.key' => '']))],
]);

it('shows Jev needs review results without changing saved answers', function (): void {
    fakeJev();
    $plan = app(CreatePlan::class)->handle('Review the household planner.');
    $result = ['status' => 'needs_review', 'reason' => 'low_confidence', 'answers_at_evaluation' => [], 'sources' => []];
    $this->mock(SuggestPlanReview::class)->shouldReceive('handle')->once()
        ->withArgs(fn (Plan $candidate) => $candidate->id === $plan->id)
        ->andReturnUsing(function (Plan $candidate) use ($result): array {
            $candidate->update(['suggestion' => $result]);

            return $result;
        });
    $this->get('/molly/plans/'.$plan->id)->assertOk()->assertSee('Ask for a Tarpit suggestion');
    $this->post('/molly/plans/'.$plan->id.'/suggest')->assertRedirect(route('molly.plans.show', $plan->id));
    $this->get('/molly/plans/'.$plan->id)->assertOk()->assertSee('needs_review')->assertSee('low_confidence')->assertDontSee('No model has evaluated the project.')->assertDontSee('Jev classification is off or not configured');
    expect($plan->refresh()->answers)->toBe([])->and(Task::count())->toBe(0);
});

it('shows only tasks created from the current plan', function (): void {
    $plan = app(CreatePlan::class)->handle('Build a routine.', false);
    $task = Task::create(['prompt' => 'First task', 'workspace' => $this->planningWorkspace, 'paths' => ['app.php'], 'test_path' => 'tests/Test.php', 'source' => ['plan_id' => $plan->id]]);
    $other = Task::create(['prompt' => 'Unrelated task', 'workspace' => $this->planningWorkspace, 'paths' => ['app.php'], 'test_path' => 'tests/Test.php', 'source' => ['plan_id' => 'other']]);
    $this->get('/molly/plans/'.$plan->id)->assertOk()->assertSee(route('molly.tasks.show', $task->id), false)
        ->assertSee('pending')->assertDontSee($other->id);
});
