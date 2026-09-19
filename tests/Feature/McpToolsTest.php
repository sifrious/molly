<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Sifrious\Molly\Actions\ApproveTask;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\IndexLaravelKnowledge;
use Sifrious\Molly\Actions\IndexNativePhpKnowledge;
use Sifrious\Molly\Actions\IndexTarpitKnowledge;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Contracts\DisplayStatus;
use Sifrious\Molly\Jobs\StartSavedTask;
use Sifrious\Molly\Mcp\MollyGuide;
use Sifrious\Molly\Mcp\MollyKnowledge;
use Sifrious\Molly\Mcp\MollyPlan;
use Sifrious\Molly\Mcp\MollyServer;
use Sifrious\Molly\Mcp\MollyTask;
use Sifrious\Molly\Models\Plan;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;

function mcpTaskScope(): array
{
    $workspace = sys_get_temp_dir().'/molly-mcp-'.Str::uuid();
    File::ensureDirectoryExists($workspace.'/tests');
    File::ensureDirectoryExists($workspace.'/app');
    File::put($workspace.'/app/Hello.php', '<?php');
    writeProtectedTest($workspace, 'tests/Hello.php');
    test()->mcpWorkspace = $workspace;

    return ['workspace' => $workspace, 'paths' => ['app/Hello.php'], 'test_path' => 'tests/Hello.php'];
}

afterEach(function () {
    if (isset($this->mcpWorkspace)) {
        File::deleteDirectory($this->mcpWorkspace);
    }
});

it('publishes local MCP tools and reads offline citations', function () {
    MollyServer::tools()->assertRegistered([MollyGuide::class, MollyKnowledge::class, MollyPlan::class, MollyTask::class]);
    MollyServer::tool(MollyGuide::class, ['operation' => 'graph'])->assertOk()
        ->assertSee(['step:outcome', 'nativephp-mobile', 'sha256']);
    MollyServer::tool(MollyGuide::class, ['operation' => 'source', 'id' => 'laravel-queues'])->assertOk()
        ->assertSee(['Laravel 13 queues', 'Laravel queues provide', 'b94b890362111c44de223e09502c610a9d9f20d8']);
    MollyServer::tool(MollyGuide::class, ['operation' => 'source', 'id' => '../private'])->assertHasErrors()->assertSee('GUIDE_SOURCE_NOT_FOUND');
});

it('returns indexed Laravel knowledge with provenance', function () {
    $database = sys_get_temp_dir().'/molly-mcp-knowledge-'.Str::uuid().'.sqlite';
    config()->set('molly.knowledge.database', $database);
    app(IndexLaravelKnowledge::class)->handle('13');

    MollyServer::tool(MollyKnowledge::class, ['concept' => 'Queue', 'version' => '13', 'depth' => 3, 'limit' => 40])
        ->assertOk()
        ->assertSee(['Queue', 'Retry', 'ShouldQueue', 'sources', 'revision']);

    File::delete($database);
});

it('returns indexed NativePHP knowledge without mixing desktop and mobile', function () {
    $database = sys_get_temp_dir().'/molly-mcp-knowledge-'.Str::uuid().'.sqlite';
    config()->set('molly.knowledge.database', $database);
    app(IndexNativePhpKnowledge::class)->handle();

    MollyServer::tool(MollyKnowledge::class, ['concept' => 'Mobile', 'namespace' => 'nativephp', 'version' => 'mobile-4', 'depth' => 1, 'limit' => 20])
        ->assertOk()
        ->assertSee(['Mobile', 'nativephp', 'mobile-4', 'sources', 'revision'])
        ->assertDontSee('Desktop v2');

    File::delete($database);
});

it('returns indexed tarpit notes without mixing Laravel or NativePHP', function () {
    $database = sys_get_temp_dir().'/molly-mcp-knowledge-'.Str::uuid().'.sqlite';
    config()->set('molly.knowledge.database', $database);
    app(IndexTarpitKnowledge::class)->handle();

    MollyServer::tool(MollyKnowledge::class, ['concept' => 'Tarpit', 'namespace' => 'tarpit', 'depth' => 1, 'limit' => 20])
        ->assertOk()
        ->assertSee(['Tarpit', 'tarpit', 'notes-', 'sources', 'revision'])
        ->assertDontSee('ShouldQueue');

    File::delete($database);
});

it('requires a source ID through native MCP validation', function () {
    MollyServer::tool(MollyGuide::class, ['operation' => 'source'])->assertHasErrors(['id']);
});

it('saves a plan and advances through persisted decisions with citations', function () {
    MollyServer::tool(MollyPlan::class, ['operation' => 'create', 'description' => 'Create a mobile queue dashboard.'])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('completed', false)->where('next_step.id', 'outcome')->has('sources')->etc());
    $plan = Plan::sole();
    foreach (['outcome', 'state', 'laravel', 'boundaries', 'verification'] as $step) {
        MollyServer::tool(MollyPlan::class, ['operation' => 'answer', 'id' => $plan->id, 'step' => $step, 'answer' => 'Decision for '.$step])->assertOk();
    }
    expect($plan->fresh()->answers)->toHaveCount(5);
    MollyServer::tool(MollyPlan::class, ['operation' => 'show', 'id' => $plan->id])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('completed', true)->where('next_step', null)->has('sources')->etc());
    expect(Task::count())->toBe(0)->and(Run::count())->toBe(0);
});

it('rejects out of order planning answers without saving them', function () {
    MollyServer::tool(MollyPlan::class, ['operation' => 'create', 'description' => 'Plan a report.'])->assertOk();
    $plan = Plan::sole();
    MollyServer::tool(MollyPlan::class, ['operation' => 'answer', 'id' => $plan->id, 'step' => 'verification', 'answer' => 'Skip ahead.'])
        ->assertHasErrors()->assertSee('PLAN_STEP_INVALID');
    expect($plan->fresh()->answers)->toBe([]);
});

it('preserves an explicit skipped planning review when creating a task', function () {
    Queue::fake();
    MollyServer::tool(MollyPlan::class, ['operation' => 'create', 'description' => 'Plan a queue report.', 'guided' => false])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('completed', true)->where('next_step', null)->etc());
    $plan = Plan::sole();
    MollyServer::tool(MollyTask::class, ['operation' => 'from_plan', 'plan_id' => $plan->id, 'prompt' => 'Show pending jobs.', ...mcpTaskScope()])->assertOk();
    $task = Task::sole();
    expect($task->source['plan_id'])->toBe($plan->id)->and($task->source['planning']['review_mode'])->toBe('skip')
        ->and($task->source['citations'])->not->toBeEmpty()->and($task->status)->toBe('pending')->and(Run::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('does not create a task from an unfinished plan', function () {
    MollyServer::tool(MollyPlan::class, ['operation' => 'create', 'description' => 'Plan a report.'])->assertOk();
    MollyServer::tool(MollyTask::class, ['operation' => 'from_plan', 'plan_id' => Plan::sole()->id, 'prompt' => 'Show jobs.', ...mcpTaskScope()])
        ->assertHasErrors()->assertSee('PLAN_INCOMPLETE');
    expect(Task::count())->toBe(0);
});

it('creates reads lists and stops a saved task without running it', function () {
    Queue::fake();
    MollyServer::tool(MollyTask::class, ['operation' => 'create', 'prompt' => 'Return Hello.', ...mcpTaskScope()])->assertOk();
    $task = Task::sole();
    MollyServer::tool(MollyTask::class, ['operation' => 'show', 'id' => $task->id])->assertOk()
        ->assertSee('Return Hello.')
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('display_status', DisplayStatus::Pending->value)->where('linked_pr', null)->where('issue_url', null)->etc());
    MollyServer::tool(MollyTask::class, ['operation' => 'list', 'limit' => 1])->assertOk()->assertSee($task->id);
    MollyServer::tool(MollyTask::class, ['operation' => 'stop', 'id' => $task->id])->assertOk();
    expect($task->fresh()->status)->toBe('stopped')->and(Run::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('returns actual run evidence without upgrading failed or skipped checks', function () {
    $scope = mcpTaskScope();
    $task = app(CreateTask::class)->handle('Return Hello.', $scope['workspace'], $scope['paths'], $scope['test_path']);
    $run = Run::create(['task_id' => $task->id, 'status' => 'failed', 'prompt' => $task->prompt, 'workspace' => $task->workspace, 'report' => ['tests' => ['passed' => false], 'complexity' => ['status' => 'skipped']]]);
    MollyServer::tool(MollyTask::class, ['operation' => 'show_run', 'id' => $run->id])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('run.status', 'failed')->where('run.report.tests.passed', false)->where('run.report.complexity.status', 'skipped')->etc());
});

it('queues start and retry promptly with the saved task ID', function (string $operation) {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 3700);
    Queue::fake([StartSavedTask::class]);
    $scope = mcpTaskScope();
    $task = app(CreateTask::class)->handle('Return Hello.', $scope['workspace'], $scope['paths'], $scope['test_path']);
    MollyServer::tool(MollyTask::class, ['operation' => $operation, 'id' => $task->id])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('queued', true)->where('task.status', 'pending')->etc());
    Queue::assertPushed(StartSavedTask::class, fn (StartSavedTask $job) => $job->taskId === $task->id && $job->retry === ($operation === 'retry'));
    expect(Run::count())->toBe(0);
})->with(['start', 'retry']);

it('rejects unsafe queue configuration without dispatching work', function (string $driver, int $retryAfter, string $message) {
    config()->set('queue.default', 'mcp-test');
    config()->set('queue.connections.mcp-test', ['driver' => $driver, 'retry_after' => $retryAfter]);
    Queue::fake();
    $scope = mcpTaskScope();
    $task = app(CreateTask::class)->handle('Return Hello.', $scope['workspace'], $scope['paths'], $scope['test_path']);
    MollyServer::tool(MollyTask::class, ['operation' => 'start', 'id' => $task->id])->assertHasErrors()->assertSee($message);
    Queue::assertNothingPushed();
})->with([
    ['sync', 3700, 'Choose a database'],
    ['database', 3600, 'retry_after above 3600'],
]);

it('returns errors for missing saved records', function (string $operation, string $message) {
    MollyServer::tool(MollyTask::class, ['operation' => $operation, 'id' => (string) Str::uuid()])->assertHasErrors()->assertSee($message);
})->with([['show', 'TASK_NOT_FOUND'], ['show_run', 'RUN_NOT_FOUND'], ['start', 'TASK_NOT_FOUND'], ['stop', 'TASK_NOT_FOUND']]);

it('records human approval through MCP without opening a pull request', function () {
    $scope = mcpTaskScope();
    $task = app(CreateTask::class)->handle('Return Hello.', $scope['workspace'], $scope['paths'], $scope['test_path']);
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true]);
    $this->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle')->once()->andReturn([
        'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']),
        'findings' => [],
    ]);
    ChangeWriter::fake([['summary' => 'Return Hello.', 'files' => [['path' => 'app/Hello.php', 'content' => '<?php return "Hello";']]]])->preventStrayPrompts();
    config(['molly.parallel_checks' => false]);
    app(StartTask::class)->handle($task->id);

    MollyServer::tool(MollyTask::class, ['operation' => 'approve', 'id' => $task->id])->assertHasErrors(['approve']);
    MollyServer::tool(MollyTask::class, ['operation' => 'approve', 'id' => $task->id, 'approve' => false])
        ->assertHasErrors()->assertSee('APPROVAL_UNCONFIRMED');

    MollyServer::tool(MollyTask::class, ['operation' => 'approve', 'id' => $task->id, 'approve' => true])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('approved', true)->where('display_status', DisplayStatus::Approved->value)->where('task_id', $task->id)->etc());

    $log = app(RecordLifecycleEvent::class)->load($scope['workspace']);
    expect($log->displayStatus($task->id))->toBe(DisplayStatus::Approved)
        ->and($log->events($task->id)[array_key_last($log->events($task->id))]->payload['pull_request_opened'] ?? true)->toBeFalse();
});

it('records an opened pull request and merge through MCP without opening GitHub', function () {
    $scope = mcpTaskScope();
    $task = app(CreateTask::class)->handle('Return Hello.', $scope['workspace'], $scope['paths'], $scope['test_path']);
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true]);
    $this->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle')->once()->andReturn([
        'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']),
        'findings' => [],
    ]);
    ChangeWriter::fake([['summary' => 'Return Hello.', 'files' => [['path' => 'app/Hello.php', 'content' => '<?php return "Hello";']]]])->preventStrayPrompts();
    config(['molly.parallel_checks' => false]);
    app(StartTask::class)->handle($task->id);
    app(ApproveTask::class)->handle($task->id, true);
    $url = 'https://github.com/sifrious/molly/pull/12';
    $sha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    MollyServer::tool(MollyTask::class, ['operation' => 'pr_opened', 'id' => $task->id, 'url' => $url])->assertHasErrors(['approve']);
    MollyServer::tool(MollyTask::class, ['operation' => 'pr_opened', 'id' => $task->id, 'url' => $url, 'approve' => true])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('opened', false)->where('recorded', true)->where('pull_request_url', $url)->etc());
    MollyServer::tool(MollyTask::class, ['operation' => 'merged', 'id' => $task->id, 'sha' => $sha])->assertHasErrors(['approve']);
    MollyServer::tool(MollyTask::class, ['operation' => 'merged', 'id' => $task->id, 'sha' => $sha, 'approve' => true])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('merged', false)->where('recorded', true)->where('display_status', DisplayStatus::Merged->value)->etc());

    $log = app(RecordLifecycleEvent::class)->load($scope['workspace']);
    expect($log->displayStatus($task->id))->toBe(DisplayStatus::Merged)
        ->and(array_map(fn ($event) => $event->type()?->value, $log->events($task->id)))->toContain('pull_request_opened', 'merged');
});

it('validates task arguments before saving or dispatching', function () {
    MollyServer::tool(MollyTask::class, ['operation' => 'create'])->assertHasErrors(['prompt', 'workspace', 'test path']);
    MollyServer::tool(MollyTask::class, ['operation' => 'list', 'limit' => 101])->assertHasErrors(['limit']);
    MollyServer::tool(MollyPlan::class, ['operation' => 'create'])->assertHasErrors(['description']);
    MollyServer::tool(MollyPlan::class, ['operation' => 'show', 'id' => (string) Str::uuid()])->assertHasErrors()->assertSee('PLAN_NOT_FOUND');
    expect(Task::count())->toBe(0)->and(Plan::count())->toBe(0);
});

it('imports issue context through the existing GitHub action without executing it', function () {
    $url = 'https://github.com/example/project/issues/7';
    Process::preventStrayProcesses();
    Process::fake(["'gh' 'api' '--hostname' 'github.com' 'repos/example/project/issues/7'" => Process::result(output: json_encode([
        'html_url' => $url, 'number' => 7, 'title' => 'Show jobs', 'body' => 'Show pending jobs.', 'updated_at' => '2026-09-17T12:00:00Z', 'labels' => [],
    ]))]);
    MollyServer::tool(MollyTask::class, ['operation' => 'import_github', 'issue_url' => $url, ...mcpTaskScope()])->assertOk();
    expect(Task::sole()->source['issue_number'])->toBe(7)->and(Run::count())->toBe(0);
    Process::assertRan(['gh', 'api', '--hostname', 'github.com', 'repos/example/project/issues/7']);
});

it('returns application scope errors without saving an unsafe task', function () {
    $scope = mcpTaskScope();
    MollyServer::tool(MollyTask::class, ['operation' => 'create', 'prompt' => 'Read private data.', ...$scope, 'paths' => ['../private.php', 'tests/Hello.php']])
        ->assertHasErrors()->assertSee('PATH_INVALID');
    expect(Task::count())->toBe(0);
});

it('persists a TypeSafe fallback suggestion without answering the plan', function () {
    config()->set('molly.typesafe.enabled', false);
    MollyServer::tool(MollyPlan::class, ['operation' => 'create', 'description' => 'Plan a mobile report.'])->assertOk();
    $plan = Plan::sole();
    MollyServer::tool(MollyPlan::class, ['operation' => 'suggest', 'id' => $plan->id])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('completed', false)->where('next_step.id', 'outcome')->has('plan.suggestion.status')->has('sources')->etc());
    expect($plan->fresh()->suggestion)->not->toBeNull()->and($plan->fresh()->answers)->toBe([]);
});

it('returns the persisted TypeSafe choice confidence and citations', function () {
    config()->set('molly.typesafe.enabled', true);
    config()->set('molly.typesafe.api_key', 'test-key');
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response([
        'model' => 'jev-latest', 'answers' => ['focus' => [
            'type' => 'choice', 'choice' => 'state', 'confidence' => 0.9,
            'probabilities' => ['outcome' => 0, 'state' => 1, 'laravel' => 0, 'boundaries' => 0, 'verification' => 0],
        ]],
    ])]);
    MollyServer::tool(MollyPlan::class, ['operation' => 'create', 'description' => 'Plan a report.'])->assertOk();
    $plan = Plan::sole();
    MollyServer::tool(MollyPlan::class, ['operation' => 'suggest', 'id' => $plan->id])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('plan.suggestion.focus', 'state')->where('plan.suggestion.confidence', 0.9)->has('plan.suggestion.sources.0.url')->where('next_step.id', 'outcome')->etc());
    expect($plan->fresh()->suggestion['focus'])->toBe('state')->and($plan->fresh()->answers)->toBe([]);
    Http::assertSentCount(1);
});
