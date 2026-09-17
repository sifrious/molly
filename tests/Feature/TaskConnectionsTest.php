<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\FindTaskConnections;
use Sifrious\Molly\Actions\LinkTaskThread;
use Sifrious\Molly\Actions\NameTask;
use Sifrious\Molly\Actions\ReadAmpConnections;
use Sifrious\Molly\Models\Task;

function connectionTask(string $name): Task
{
    return Task::create(['nickname' => $name, 'prompt' => 'Check the greeting.', 'workspace' => sys_get_temp_dir(),
        'paths' => ['tests/GreetingTest.php'], 'test_path' => 'tests/GreetingTest.php']);
}

beforeEach(function () {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('session.driver', 'array');
    config()->set('molly.ui.enabled', true);
    Process::preventStrayProcesses();
    Queue::fake();
});

it('records exact task history and preserves links after a nickname changes', function () {
    $first = connectionTask('greeting');
    $second = connectionTask('health');
    $thread = 'T-'.Str::uuid();
    $link = app(LinkTaskThread::class);

    $initial = $link->handle('greeting', strtolower($thread));
    expect($link->handle($first->id, $thread))->toBe($initial);
    $link->handle('health', $thread);
    app(NameTask::class)->handle('greeting', 'welcome');
    $history = app(FindTaskConnections::class)->handle('welcome', false);

    expect($history['task_id'])->toBe($first->id)
        ->and($history['matches'][0])->toMatchArray([
            'thread_id' => $thread, 'association' => 'prior_exact', 'source' => 'user', 'connection' => 'unknown',
            'latest_task' => ['id' => $second->id, 'reference' => 'health'],
        ])
        ->and($first->fresh()->status)->toBe('pending');

    $link->handle('welcome', $thread);
    expect(app(FindTaskConnections::class)->handle($first->id, false)['matches'][0]['association'])->toBe('latest_recorded');
    $this->assertDatabaseCount('molly_task_threads', 3);
    $this->assertDatabaseCount('molly_runs', 0);
    Queue::assertNothingPushed();
});

it('ranks connected latest and prior matches while keeping unknown history visible', function () {
    $task = connectionTask('greeting');
    connectionTask('other-task');
    $latest = 'T-'.Str::uuid();
    $prior = 'T-'.Str::uuid();
    $offline = 'T-'.Str::uuid();
    $unknown = 'T-'.Str::uuid();
    foreach ([$latest, $prior, $offline, $unknown] as $thread) {
        app(LinkTaskThread::class)->handle($task->id, $thread);
    }
    app(LinkTaskThread::class)->handle('other-task', $prior);
    $this->mock(ReadAmpConnections::class)->shouldReceive('handle')->once()->with([$unknown, $offline, $prior, $latest])->andReturn([
        'status' => 'observed', 'reason' => null, 'observed_at' => '2026-09-17T07:00:00Z', 'provider_version' => 'test',
        'threads' => [
            ['thread_id' => $latest, 'status' => 'observed', 'executor_connected' => true, 'working' => true, 'executor_type' => 'sandbox'],
            ['thread_id' => $prior, 'status' => 'observed', 'executor_connected' => true, 'working' => false],
            ['thread_id' => $offline, 'status' => 'observed', 'executor_connected' => false, 'working' => false],
            ['thread_id' => $unknown, 'status' => 'unknown', 'executor_connected' => true, 'working' => true],
        ],
    ]);

    $result = app(FindTaskConnections::class)->handle('greeting');

    expect(array_column($result['matches'], 'thread_id'))->toBe([$latest, $prior, $unknown, $offline])
        ->and(array_column($result['matches'], 'connection'))->toBe(['connected', 'connected', 'unknown', 'disconnected'])
        ->and($result['matches'][2]['working'])->toBeNull()
        ->and($result['matches'][0]['executor_type'])->toBe('sandbox')
        ->and($result['orb_identity'])->toBe('unverified')
        ->and($result['observed_at'])->toBe('2026-09-17T07:00:00Z');
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('molly_runs', 0);
});

it('does not contact Amp for empty or stored-only history', function () {
    connectionTask('greeting');
    $this->mock(ReadAmpConnections::class)->shouldNotReceive('handle');

    expect(app(FindTaskConnections::class)->handle('greeting'))->toMatchArray(['status' => 'not_checked', 'matches' => [], 'observed_at' => null]);
    app(LinkTaskThread::class)->handle('greeting', 'T-'.Str::uuid());
    expect(app(FindTaskConnections::class)->handle('greeting', false)['matches'][0]['connection'])->toBe('unknown');
});

it('limits connection probes to the twenty most recent exact associations', function () {
    $task = connectionTask('greeting');
    foreach (range(1, 21) as $index) {
        app(LinkTaskThread::class)->handle($task->id, 'T-'.Str::uuid());
    }

    $result = app(FindTaskConnections::class)->handle('greeting', false);

    expect($result['truncated'])->toBeTrue()->and($result['matches'])->toHaveCount(20)
        ->and(array_column($result['matches'], 'association_id'))->toBe(range(21, 2));
});

it('rejects invalid thread identifiers before storing a link', function (string $thread) {
    connectionTask('greeting');

    expect(fn () => app(LinkTaskThread::class)->handle('greeting', $thread))->toThrow(RuntimeException::class, 'AMP_THREAD_INVALID');
    $this->assertDatabaseCount('molly_task_threads', 0);
})->with(['empty' => '', 'option' => '--help', 'URL' => 'https://ampcode.com/threads/T-123', 'shell' => 'T-$(whoami)', 'ordinary UUID' => '00000000-0000-0000-0000-000000000000']);

it('returns a task error for missing references without storing or probing', function () {
    $this->mock(ReadAmpConnections::class)->shouldNotReceive('handle');
    expect(fn () => app(LinkTaskThread::class)->handle('missing', 'T-'.Str::uuid()))->toThrow(RuntimeException::class, 'TASK_NOT_FOUND')
        ->and(fn () => app(FindTaskConnections::class)->handle('missing'))->toThrow(RuntimeException::class, 'TASK_NOT_FOUND');
    $this->assertDatabaseCount('molly_task_threads', 0);
});

it('returns the same stored association evidence through the CLI and web', function () {
    $task = connectionTask('greeting');
    $thread = 'T-'.Str::uuid();
    $this->mock(ReadAmpConnections::class)->shouldNotReceive('handle');

    expect(Artisan::call('molly:link-thread', ['task' => 'greeting', 'thread' => $thread, '--json' => true]))->toBe(0);
    $link = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($link['task_id'])->toBe($task->id)->and($link['thread_id'])->toBe($thread);
    expect(Artisan::call('molly:connections', ['task' => 'greeting', '--stored' => true, '--json' => true]))->toBe(0);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $this->get('/molly/tasks/greeting/connections')->assertOk()->assertViewHas('connections', $output)
        ->assertSee($thread)->assertSee('Unknown')->assertSee('Latest recorded task')
        ->assertSee('name="_token"', false)->assertSee('for="thread"', false);
    $this->get('/molly/tasks/'.$task->id)->assertSee('Find linked Amp threads');
    Queue::assertNothingPushed();
});

it('links threads through a native form and reports validation failures', function () {
    $task = connectionTask('greeting');
    $thread = 'T-'.Str::uuid();

    $this->post('/molly/tasks/greeting/connections', ['thread' => $thread, 'task_id' => 'ignored', 'status' => 'completed'])
        ->assertRedirect(route('molly.tasks.connections', $task->id))->assertSessionHasNoErrors();
    $this->assertDatabaseHas('molly_task_threads', ['task_id' => $task->id, 'thread_id' => $thread]);
    expect($task->fresh()->status)->toBe('pending');
    $this->post('/molly/tasks/greeting/connections', ['thread' => '--help'])->assertSessionHasErrors('thread');
    $this->assertDatabaseCount('molly_task_threads', 1);
    $this->get('/molly/tasks/missing/connections')->assertNotFound();
    $this->post('/molly/tasks/missing/connections', ['thread' => $thread])->assertNotFound();
    $this->post('/molly/tasks/missing/connections/refresh')->assertNotFound();
});

it('checks current status on explicit web refresh without changing saved history', function () {
    $task = connectionTask('greeting');
    $thread = 'T-'.Str::uuid();
    app(LinkTaskThread::class)->handle($task->id, $thread);
    $before = DB::table('molly_task_threads')->get()->toArray();
    $this->mock(ReadAmpConnections::class)->shouldReceive('handle')->once()->with([$thread])->andReturn([
        'status' => 'unavailable', 'reason' => 'Amp is not installed.', 'observed_at' => '2026-09-17T07:00:00Z', 'provider_version' => null, 'threads' => [],
    ]);

    $this->post('/molly/tasks/greeting/connections/refresh')->assertOk()->assertSee('Amp is not installed.')->assertSee('Unknown');

    expect(DB::table('molly_task_threads')->get()->toArray())->toEqual($before);
    Queue::assertNothingPushed();
});

it('protects connection reads and mutations from remote clients', function () {
    $task = connectionTask('greeting');
    $this->mock(ReadAmpConnections::class)->shouldNotReceive('handle');
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);

    $this->get('/molly/tasks/'.$task->id.'/connections')->assertForbidden();
    $this->post('/molly/tasks/'.$task->id.'/connections', ['thread' => 'T-'.Str::uuid()])->assertForbidden();
    $this->post('/molly/tasks/'.$task->id.'/connections/refresh')->assertForbidden();
    $this->assertDatabaseCount('molly_task_threads', 0);
});

it('requires CSRF protection on connection forms', function () {
    $task = connectionTask('greeting');
    $this->mock(ReadAmpConnections::class)->shouldNotReceive('handle');
    $this->app->detectEnvironment(fn () => 'local');
    try {
        $this->post('/molly/tasks/'.$task->id.'/connections', ['thread' => 'T-'.Str::uuid()])->assertStatus(419);
        $this->post('/molly/tasks/'.$task->id.'/connections/refresh')->assertStatus(419);
        $this->assertDatabaseCount('molly_task_threads', 0);
    } finally {
        $this->app->detectEnvironment(fn () => 'testing');
    }
});
