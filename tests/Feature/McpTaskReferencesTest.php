<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Sifrious\Molly\Actions\CreatePlan;
use Sifrious\Molly\Actions\FindTaskConnections;
use Sifrious\Molly\Actions\ReadAmpConnections;
use Sifrious\Molly\Jobs\StartSavedTask;
use Sifrious\Molly\Mcp\MollyConnections;
use Sifrious\Molly\Mcp\MollyServer;
use Sifrious\Molly\Mcp\MollyTask;
use Sifrious\Molly\Models\Task;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-mcp-reference-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/tests');
    writeProtectedTest($this->workspace, 'tests/HealthTest.php');
    $this->scope = ['workspace' => $this->workspace, 'paths' => [], 'test_path' => 'tests/HealthTest.php', 'allow_test_edits' => true];
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('creates names and queues tasks through MCP using the same nickname and scope rules', function () {
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 3700]);
    MollyServer::tool(MollyTask::class, ['operation' => 'create', 'prompt' => 'Test health.', 'nickname' => 'health', ...$this->scope])
        ->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json->where('task.nickname', 'health')->where('task.paths', ['tests/HealthTest.php'])->etc());
    $task = Task::sole();
    MollyServer::tool(MollyTask::class, ['operation' => 'name', 'id' => 'health', 'nickname' => 'readiness'])->assertOk();
    MollyServer::tool(MollyTask::class, ['operation' => 'show', 'id' => 'readiness'])->assertOk()->assertSee($task->id);
    MollyServer::tool(MollyTask::class, ['operation' => 'start', 'id' => 'readiness'])->assertOk();

    Queue::assertPushed(StartSavedTask::class, fn (StartSavedTask $job): bool => $job->taskId === $task->id && ! $job->retry);
    expect($task->fresh()->nickname)->toBe('readiness')->and($task->fresh()->status)->toBe('pending');
});

it('requires a valid nickname for MCP rename without changing the task', function () {
    MollyServer::tool(MollyTask::class, ['operation' => 'create', 'prompt' => 'Test health.', 'nickname' => 'health', ...$this->scope])->assertOk();
    MollyServer::tool(MollyTask::class, ['operation' => 'name', 'id' => 'health'])->assertHasErrors(['nickname']);
    MollyServer::tool(MollyTask::class, ['operation' => 'name', 'id' => 'health', 'nickname' => 'has spaces'])->assertHasErrors()->assertSee('TASK_NAME_INVALID');
    expect(Task::sole()->nickname)->toBe('health');
});

it('links an Amp thread and reads identical stored evidence over CLI MCP and the action', function () {
    $this->mock(ReadAmpConnections::class)->shouldNotReceive('handle');
    MollyServer::tools()->assertRegistered([MollyConnections::class]);
    MollyServer::tool(MollyTask::class, ['operation' => 'create', 'prompt' => 'Test health.', 'nickname' => 'health', ...$this->scope])->assertOk();
    $thread = 'T-'.Str::uuid();
    MollyServer::tool(MollyTask::class, ['operation' => 'link_thread', 'id' => 'health', 'thread' => $thread])->assertOk();
    $expected = app(FindTaskConnections::class)->handle('health', false);
    MollyServer::tool(MollyConnections::class, ['task' => 'health', 'stored' => true])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->whereAll($expected));
    expect(Artisan::call('molly:connections', ['task' => 'health', '--stored' => true, '--json' => true]))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe($expected);
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('molly_runs', 0);
});

it('preserves unavailable live observations over MCP without implying an Orb connection', function () {
    MollyServer::tool(MollyTask::class, ['operation' => 'create', 'prompt' => 'Test health.', 'nickname' => 'health', ...$this->scope])->assertOk();
    $thread = 'T-'.Str::uuid();
    MollyServer::tool(MollyTask::class, ['operation' => 'link_thread', 'id' => 'health', 'thread' => $thread])->assertOk();
    $this->mock(ReadAmpConnections::class)->shouldReceive('handle')->once()->with([$thread])->andReturn([
        'status' => 'unavailable', 'reason' => 'AMP_AUTHENTICATION_FAILED', 'provider_version' => null,
        'observed_at' => now()->toIso8601String(), 'threads' => [],
    ]);

    MollyServer::tool(MollyConnections::class, ['task' => 'health'])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('status', 'unavailable')->where('matches.0.connection', 'unknown')->where('orb_identity', 'unverified')->etc());
});

it('validates connection tool input without linking or probing', function () {
    $this->mock(ReadAmpConnections::class)->shouldNotReceive('handle');
    MollyServer::tool(MollyConnections::class, [])->assertHasErrors(['task']);
    MollyServer::tool(MollyConnections::class, ['task' => 'missing'])->assertHasErrors()->assertSee('TASK_NOT_FOUND');
    MollyServer::tool(MollyTask::class, ['operation' => 'link_thread'])->assertHasErrors(['id', 'thread']);
    $this->assertDatabaseCount('molly_task_threads', 0);
});

it('creates a named test-only task from a completed plan over MCP', function () {
    $plan = app(CreatePlan::class)->handle('Test application health.', false);
    MollyServer::tool(MollyTask::class, ['operation' => 'from_plan', 'plan_id' => $plan->id, 'prompt' => 'Test health.', 'nickname' => 'health', ...$this->scope])->assertOk();

    expect(Task::sole()->nickname)->toBe('health')->and(Task::sole()->paths)->toBe(['tests/HealthTest.php'])
        ->and(Task::sole()->source['plan_id'])->toBe($plan->id);
});

it('creates a named test-only task from a native plan form', function () {
    config(['molly.ui.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'session.driver' => 'array']);
    $plan = app(CreatePlan::class)->handle('Test application health.', false);

    $this->get(route('molly.plans.show', $plan->id))->assertOk()->assertSee('Task nickname, optional')->assertSee('Files Molly may change');
    $this->post(route('molly.plans.tasks', $plan->id), ['prompt' => 'Test health.', 'nickname' => 'health', 'workspace' => $this->workspace, 'paths' => '', 'test_path' => 'tests/HealthTest.php', 'allow_test_edits' => '1'])
        ->assertSessionHasNoErrors()->assertRedirect(route('molly.tasks.show', Task::sole()->id));
    expect(Task::sole()->nickname)->toBe('health')->and(Task::sole()->paths)->toBe(['tests/HealthTest.php']);
    Queue::assertNothingPushed();
});
