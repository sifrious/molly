<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;
use Sifrious\Molly\Mcp\MollyServer;
use Sifrious\Molly\Mcp\MollyTask;
use Sifrious\Molly\Models\Task;

beforeEach(function () {
    $this->freezeTime();
    config(['molly.ui.enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'session.driver' => 'array', 'molly.typesafe.enabled' => false]);
    Queue::fake();
    $this->task = Task::create(['nickname' => 'health', 'prompt' => 'Test health.', 'workspace' => sys_get_temp_dir(),
        'paths' => ['tests/HealthTest.php'], 'test_path' => 'tests/HealthTest.php', 'status' => 'failed']);
    $this->run = $this->task->runs()->create(['prompt' => $this->task->prompt, 'workspace' => $this->task->workspace,
        'status' => 'failed', 'report' => ['verification' => ['status' => 'failed', 'tests' => 1, 'failures' => 1],
            'review' => ['checks' => [], 'findings' => []]]]);
});

it('shares deterministic fallback advice across CLI MCP and the native web form', function () {
    expect(Artisan::call('molly:advice', ['task' => 'health', '--json' => true]))->toBe(0);
    $expected = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    $this->post('/molly/tasks/health/advice')->assertOk()->assertViewHas('advice', $expected)
        ->assertSee('TypeSafe is disabled')->assertSee('Retry permitted')->assertSee('No usable model recommendation was applied.')
        ->assertSee('Molly used saved task state and checks.');
    MollyServer::tool(MollyTask::class, ['operation' => 'advice', 'id' => 'health'])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('advice', $expected));
    expect($this->run->fresh()->report['advice'])->toBe($expected)
        ->and($this->run->fresh()->report['verification']['failures'])->toBe(1)
        ->and($this->run->fresh()->status)->toBe('failed')->and($this->task->fresh()->status)->toBe('failed');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
    $this->get('/molly/runs/'.$this->run->id)->assertOk()->assertSee('Saved next-step advice')->assertSee('TypeSafe is disabled');
    $this->artisan('molly:show', ['run' => $this->run->id])->expectsOutputToContain('Saved next-step advice')->assertSuccessful();
});

it('does not call TypeSafe when reading the task page and exposes a protected native advice form', function () {
    config(['molly.typesafe.enabled' => true, 'molly.typesafe.api_key' => 'test-key']);

    $this->get('/molly/tasks/health')->assertOk()->assertSee('Get next-step advice')
        ->assertSee('action="'.route('molly.tasks.advice', $this->task->id).'"', false)->assertSee('name="_token"', false);
    expect($this->run->fresh()->report)->not->toHaveKey('advice');
    Http::assertNothingSent();
});

it('escapes model metadata and presents a retry as advice without executing it', function () {
    config(['molly.typesafe.enabled' => true, 'molly.typesafe.api_key' => 'test-key']);
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response([
        'model' => '<script>bad()</script>', 'answers' => ['next_action' => [
            'type' => 'choice', 'choice' => 'retry', 'confidence' => 0.95,
            'probabilities' => ['continue' => 0.01, 'retry' => 0.95, 'stop' => 0.02, 'needs_review' => 0.02],
        ]],
    ])]);

    $this->post('/molly/tasks/health/advice')->assertOk()->assertSee('TypeSafe recommends another bounded attempt.')
        ->assertSee('php artisan molly:retry health')->assertSee('<script>bad()</script>')->assertDontSee('<script>bad()</script>', false);
    Http::assertSentCount(1);
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('molly_runs', 1);
    expect($this->task->fresh()->status)->toBe('failed');
});

it('rejects unknown tasks and invalid MCP advice requests', function () {
    $this->post('/molly/tasks/missing/advice')->assertNotFound();
    MollyServer::tool(MollyTask::class, ['operation' => 'advice'])->assertHasErrors(['id']);
    MollyServer::tool(MollyTask::class, ['operation' => 'advice', 'id' => 'missing'])->assertHasErrors()->assertSee('TASK_NOT_FOUND');
    Http::assertNothingSent();
});

it('explains when changed evidence prevents applying a returned recommendation', function () {
    config(['molly.typesafe.enabled' => true, 'molly.typesafe.api_key' => 'test-key']);
    Http::fake(['https://api.typesafe.ai/v1/systemone' => function () {
        $this->run->update(['report' => ['verification' => ['status' => 'failed', 'tests' => 2, 'failures' => 2]]]);

        return Http::response(['answers' => ['next_action' => [
            'type' => 'choice', 'choice' => 'retry', 'confidence' => 0.95,
            'probabilities' => ['continue' => 0.01, 'retry' => 0.95, 'stop' => 0.02, 'needs_review' => 0.02],
        ]]]);
    }]);

    $this->post('/molly/tasks/health/advice')->assertOk()
        ->assertSee('Saved evidence changed during the request')
        ->assertSee('Molly used saved task state and checks.')
        ->assertDontSee('TypeSafe returned a choice from the allowed options.');
    Http::assertSentCount(1);
    Queue::assertNothingPushed();
    expect($this->run->fresh()->report['advice']['next_action'])->toBe('inspect');
});

it('rejects remote advice requests before contacting the provider', function () {
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/molly/tasks/health/advice')->assertForbidden();
    Http::assertNothingSent();
    expect($this->run->fresh()->report)->not->toHaveKey('advice');
});

it('requires a CSRF token to request advice outside the test bypass', function () {
    $this->app->detectEnvironment(fn () => 'local');
    try {
        $this->post('/molly/tasks/health/advice')->assertStatus(419);
        Http::assertNothingSent();
        expect($this->run->fresh()->report)->not->toHaveKey('advice');
    } finally {
        $this->app->detectEnvironment(fn () => 'testing');
    }
});
