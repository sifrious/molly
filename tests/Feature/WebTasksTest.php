<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\RetryTask;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Jobs\StartSavedTask;
use Sifrious\Molly\Livewire\RunStatus;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;

beforeEach(function () {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('session.driver', 'array');
    config()->set('molly.ui.enabled', true);
    config()->set('queue.connections.database.retry_after', 3700);
    $this->workspace = sys_get_temp_dir().'/molly-web-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/tests');
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

function webMollyTask(): Task
{
    return app(CreateTask::class)->handle('Return <script>alert(1)</script>.', test()->workspace, ['tests/Hello.php'], 'tests/Hello.php');
}

it('hides the UI by default and in production', function () {
    config()->set('molly.ui.enabled', false);
    $this->get('/molly')->assertNotFound();
    config()->set('molly.ui.enabled', true);
    $this->app->detectEnvironment(fn () => 'production');
    $this->get('/molly')->assertNotFound();
    $this->app->detectEnvironment(fn () => 'testing');
});

it('rejects remote clients and non-loopback hosts', function (array $server) {
    $this->withServerVariables($server)->get('http://'.($server['HTTP_HOST'] ?? 'localhost').'/molly')->assertForbidden();
})->with([
    'remote client' => [['REMOTE_ADDR' => '203.0.113.10']],
    'untrusted host' => [['HTTP_HOST' => 'attacker.example']],
    'forwarded loopback' => [['REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1']],
]);

it('renders labeled server forms and saves a task without execution', function () {
    Queue::fake();
    $this->get('/molly/tasks/create')->assertOk()->assertSee('name="_token"', false)->assertSee('for="paths"', false)->assertSee('type="submit"', false);
    $response = $this->post('/molly/tasks', ['prompt' => 'Return Hello.', 'workspace' => $this->workspace, 'paths' => "tests/Hello.php\n", 'test_path' => 'tests/Hello.php']);
    $task = Task::sole();
    $response->assertRedirect(route('molly.tasks.show', $task->id));
    expect($task->status)->toBe('pending')->and($task->paths)->toBe(['tests/Hello.php'])->and(Run::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('returns validation feedback without saving invalid scope', function () {
    $this->from('/molly/tasks/create')->post('/molly/tasks', ['prompt' => 'Hello', 'workspace' => $this->workspace, 'paths' => 'app/Hello.php', 'test_path' => 'tests/Hello.php'])
        ->assertRedirect('/molly/tasks/create')->assertSessionHasErrors('task');
    expect(Task::count())->toBe(0);
    $this->get('/molly/tasks/create')->assertSee('TEST_PATH_INVALID');
});

it('validates required form values', function () {
    $this->post('/molly/tasks', [])->assertSessionHasErrors(['prompt', 'workspace', 'paths', 'test_path']);
    expect(Task::count())->toBe(0);
});

it('renders task history and escapes saved context', function () {
    $task = webMollyTask();
    $task->update(['source' => ['issue_url' => 'javascript:alert(1)', 'issue_title' => '<script>bad()</script>']]);
    $this->get('/molly')->assertOk()->assertSee($task->prompt)->assertDontSee('<script>alert(1)</script>', false);
    $this->get('/molly/tasks/'.$task->id)->assertOk()->assertSee('No attempts recorded.')->assertSee('The queue worker starts each attempt.')->assertSee('Start task')->assertDontSee('<script>bad()</script>', false)->assertDontSee('href="javascript:', false);
});

it('queues start and retry by ID without running the task in HTTP', function (bool $retry) {
    config()->set('queue.default', 'database');
    Queue::fake();
    $task = webMollyTask();
    $this->post('/molly/tasks/'.$task->id.'/'.($retry ? 'retry' : 'start'))->assertRedirect(route('molly.tasks.show', $task->id));
    Queue::assertPushed(StartSavedTask::class, fn (StartSavedTask $job) => $job->taskId === $task->id && $job->retry === $retry && $job->tries === 1);
    expect($task->fresh()->status)->toBe('pending')->and(Run::count())->toBe(0);
})->with([false, true]);

it('rejects queue drivers that can execute during the request', function (string $driver) {
    config()->set('queue.default', 'web-test');
    config()->set('queue.connections.web-test.driver', $driver);
    Queue::fake();
    $task = webMollyTask();
    $this->post('/molly/tasks/'.$task->id.'/start')->assertSessionHasErrors('queue');
    Queue::assertNothingPushed();
})->with(['sync', 'deferred', 'null', 'failover']);

it('stops a pending task through the existing action', function () {
    $task = webMollyTask();
    $this->post('/molly/tasks/'.$task->id.'/stop')->assertRedirect(route('molly.tasks.show', $task->id));
    expect($task->fresh()->status)->toBe('stopped');
});

it('returns not found for unknown tasks and runs', function () {
    $this->get('/molly/tasks/'.Str::uuid())->assertNotFound();
    $this->get('/molly/runs/'.Str::uuid())->assertNotFound();
    $this->post('/molly/tasks/'.Str::uuid().'/start')->assertNotFound();
});

it('shows incomplete evidence and skipped measurements without claiming success', function () {
    $run = Run::create(['prompt' => 'Hello', 'workspace' => $this->workspace, 'status' => 'failed', 'report' => [
        'verification' => ['status' => 'failed', 'output' => '<script>bad()</script>'],
        'mode' => 'parallel',
        'branches' => [['kind' => 'review', 'branch_id' => 'review-1', 'status' => 'timed_out', 'failure_classification' => 'branch_timeout']],
        'complexity_before' => ['status' => 'completed', 'probes' => [['key' => 'c3', 'name' => 'Lonely files', 'status' => 'skipped', 'skip_reason' => 'No Git history', 'caveats' => ['Authorship is not ownership.']]]],
    ]]);
    $this->get('/molly/runs/'.$run->id)->assertOk()->assertSee('Run status: failed')->assertSee('branch_timeout')->assertSee('timed_out')->assertSee('G. Caches and dependencies')->assertSee('Not run')->assertSee('No Git history')->assertSee('Authorship is not ownership.')->assertSee('clever:lonely-files')->assertDontSee('<script>bad()</script>', false)->assertDontSee('Task completed.');
});

it('calls the shared start or retry action from a serialized queue job', function (bool $retry) {
    $task = webMollyTask();
    $start = Mockery::mock(StartTask::class);
    $again = Mockery::mock(RetryTask::class);
    ($retry ? $again : $start)->shouldReceive('handle')->once()->with($task->id)->andReturn(new Run);
    ($retry ? $start : $again)->shouldNotReceive('handle');
    $job = unserialize(serialize(new StartSavedTask($task->id, $retry)));
    $job->handle($start, $again);
})->with([false, true]);

it('checks the local UI guard again for live status requests', function () {
    $run = Run::create(['prompt' => 'Hello', 'workspace' => $this->workspace, 'status' => 'running', 'report' => []]);
    $component = Livewire::test(RunStatus::class, ['runId' => $run->id])->assertSee('Run status: running');
    config()->set('molly.ui.enabled', false);
    $component->call('$refresh')->assertNotFound();
});

it('requires a CSRF token before saving a task outside the test bypass', function () {
    $this->app->detectEnvironment(fn () => 'local');
    try {
        $this->post('/molly/tasks', ['prompt' => 'Hello', 'workspace' => $this->workspace, 'paths' => 'tests/Hello.php', 'test_path' => 'tests/Hello.php'])->assertStatus(419);
        expect(Task::count())->toBe(0);
    } finally {
        $this->app->detectEnvironment(fn () => 'testing');
    }
});

it('imports an issue through the same action without executing the task', function () {
    Process::fake(["'gh' 'api' '--hostname' 'github.com' 'repos/sifrious/molly/issues/1'" => Process::result(output: json_encode([
        'html_url' => 'https://github.com/sifrious/molly/issues/1', 'number' => 1, 'title' => 'Add greeting', 'body' => 'Return Hello.', 'updated_at' => '2026-09-17T12:00:00Z', 'labels' => [],
    ]))]);
    $this->post('/molly/tasks', ['issue_url' => 'https://github.com/sifrious/molly/issues/1', 'workspace' => $this->workspace, 'paths' => 'tests/Hello.php', 'test_path' => 'tests/Hello.php'])->assertRedirect()->assertSessionHasNoErrors();
    $task = Task::sole();
    expect($task->source['issue_number'])->toBe(1)->and($task->status)->toBe('pending')->and(Run::count())->toBe(0);
});

it('rejects a queue reservation shorter than the task timeout', function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 90);
    Queue::fake();
    $task = webMollyTask();
    $this->post('/molly/tasks/'.$task->id.'/start')->assertSessionHasErrors('queue');
    Queue::assertNothingPushed();
});

it('summarizes review evidence and compares recorded measurements before process details', function () {
    $run = Run::create(['prompt' => 'Hello', 'workspace' => $this->workspace, 'status' => 'failed', 'report' => [
        'review' => ['checks' => ['E' => ['status' => 'findings', 'evidence' => 'Unused wrapper.']], 'findings' => [['path' => 'app/Hello.php', 'line' => 4, 'severity' => 'blocking', 'classification' => 'accidental', 'problem' => 'Unused wrapper.', 'recommendation' => 'Call the function directly.']]],
        'complexity_before' => ['status' => 'ok', 'probes' => [['key' => 'c1', 'name' => 'Owned diff', 'status' => 'ok', 'metrics' => ['code_lines' => 18, 'files' => 0], 'caveats' => ['Line counts do not measure design quality.']]]],
        'complexity_after' => ['status' => 'ok', 'probes' => [['key' => 'c1', 'name' => 'Owned diff', 'status' => 'ok', 'metrics' => ['code_lines' => 12, 'files' => 0]]]],
        'changes' => [['path' => 'app/Hello.php', 'status' => 'modified', 'before_hash' => 'old-hash', 'after_hash' => 'new-hash']],
        'branches' => [['kind' => 'review', 'status' => 'failed', 'branch_id' => 'review-id']],
    ]]);
    $this->get('/molly/runs/'.$run->id)->assertOk()
        ->assertSee('1 of 7 checks recorded. 1 finding recorded, 1 blocking.')
        ->assertSeeInOrder(['id="tarpit"', 'id="clever"', 'id="pest"', 'id="changes"', 'Execution branch details'], false)
        ->assertSee('Owned diff before and after')->assertSee('Code lines')->assertSee('<td>18</td>', false)->assertSee('<td>12</td>', false)->assertSee('<td>0</td>', false)
        ->assertSee('Line counts do not measure design quality.')->assertSee('old-hash')->assertSee('new-hash')
        ->assertSee('File contents are not stored in this report.')->assertSee('<summary>Full measurement data as JSON</summary>', false);
});

it('keeps absent evidence distinct from zero measurements', function () {
    $run = Run::create(['prompt' => 'Hello', 'workspace' => $this->workspace, 'status' => 'running', 'report' => ['phase' => 'Reviewing complexity.']]);
    $this->get('/molly/runs/'.$run->id)->assertOk()->assertSee('Reviewing complexity.')
        ->assertSee('0 of 7 checks recorded.')->assertSee('Tests: Not recorded')->assertSee('No Clever measurements recorded.')->assertSee('No changed files recorded.');
    $run->update(['status' => 'failed']);
    $this->get('/molly/runs/'.$run->id)->assertOk()->assertDontSee('Run status: failed. Reviewing complexity.');
});
