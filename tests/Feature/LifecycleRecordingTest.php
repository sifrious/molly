<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Actions\RetryTask;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\ShowRuntimeStatus;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Actions\StopTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\AgentBus\LocalAgentBus;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Contracts\DispatchDecision;
use Sifrious\Molly\Contracts\DisplayStatus;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Execution\Sandbox;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Workspace;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-lifecycle-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($this->workspace);
    commitGitWorkspace($this->workspace);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('records task creation and reconstructs pending status after restart', function () {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $log = app(RecordLifecycleEvent::class)->load($this->workspace);

    expect($log->events($task->id))->toHaveCount(1)
        ->and($log->events($task->id)[0]->type())->toBe(LifecycleEventType::Created)
        ->and($log->displayStatus($task->id))->toBe(DisplayStatus::Pending)
        ->and($log->dispatchDecision($task->id, (string) Str::uuid()))->toBe(DispatchDecision::Needed);
});

it('records a pending stop without starting work', function () {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    app(StopTask::class)->handle($task->id);
    $log = app(RecordLifecycleEvent::class)->load($this->workspace);

    expect($log->displayStatus($task->id))->toBe(DisplayStatus::Stopped)
        ->and(array_map(fn ($event) => $event->type()?->value, $log->events($task->id)))->toContain('created', 'stopped');
});

it('records rejected edits when the agent proposes no file changes', function () {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->once()->andReturn(['status' => 'ok', 'probes' => []]);
    ChangeWriter::fake([['summary' => 'No change.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return null;']]]])->preventStrayPrompts();
    config(['molly.parallel_checks' => false]);

    $run = app(StartTask::class)->handle($task->id);
    $log = app(RecordLifecycleEvent::class)->load($this->workspace);

    expect($run->status)->toBe('failed')
        ->and($log->displayStatus($task->id))->toBe(DisplayStatus::Failed)
        ->and(array_map(fn ($event) => $event->type()?->value, $log->events($task->id)))->toContain(
            'created',
            'workspace_prepared',
            'dispatch_requested',
            'agent_started',
            'proposal_received',
            'edits_rejected',
            'failed',
        );
});

it('asks for human approval after required checks pass', function () {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true]);
    $this->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle')->once()->andReturn([
        'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']),
        'findings' => [],
    ]);
    ChangeWriter::fake([['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]]])->preventStrayPrompts();
    config(['molly.parallel_checks' => false]);

    $run = app(StartTask::class)->handle($task->id);
    $log = app(RecordLifecycleEvent::class)->load($this->workspace);

    expect($run->status)->toBe('completed')
        ->and($run->report['verification_receipts'])->not->toBeEmpty()
        ->and($run->report['verification_receipts'][0]['schema'])->toBe('molly.verification_outcome.v1')
        ->and(is_file($run->report['verification_receipts'][0]['path']))->toBeTrue()
        ->and($log->displayStatus($task->id))->toBe(DisplayStatus::AwaitingApproval)
        ->and(array_map(fn ($event) => $event->type()?->value, $log->events($task->id)))->toContain(
            'created',
            'workspace_prepared',
            'dispatch_requested',
            'agent_started',
            'proposal_received',
            'edits_accepted',
            'verification_started',
            'verification_finished',
            'approval_requested',
        )
        ->and($log->events($task->id)[1]->payload['created_worktree'] ?? true)->toBeFalse()
        ->and($log->dispatchDecision($task->id, $run->id))->toBe(DispatchDecision::AlreadyAccepted);
});

/** @return list<string|null> */
function lifecycleTypes(string $workspace, string $taskId): array
{
    return array_map(fn ($event) => $event->type()?->value, app(RecordLifecycleEvent::class)->load($workspace)->events($taskId));
}

it('keeps a refused start pending and records one start_refused event', function (string $code, Closure $refuse): void {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $before = $task->fresh()->only(['status', 'attempt_number', 'idempotency_key']);
    $restore = $refuse($this);

    try {
        expect(fn () => app(StartTask::class)->handle($task->id))->toThrow(RuntimeException::class, $code.':');
    } finally {
        $restore();
    }

    $task->refresh();
    $log = app(RecordLifecycleEvent::class)->load($this->workspace);
    $refused = $log->latestOf($task->id, LifecycleEventType::StartRefused);

    expect($task->only(['status', 'attempt_number', 'idempotency_key']))->toBe($before)
        ->and($task->worker_id)->toBeNull()
        ->and($task->lease_expires_at)->toBeNull()
        ->and($task->runs()->count())->toBe(0)
        ->and(lifecycleTypes($this->workspace, $task->id))->toBe(['created', 'start_refused'])
        ->and($refused->payload['code'])->toBe($code)
        ->and($refused->payload['message'])->toStartWith($code.': ')
        ->and($refused->payload['retry'])->toBeFalse()
        ->and($log->displayStatus($task->id))->toBe(DisplayStatus::Pending);
})->with([
    'git missing' => ['GIT_MISSING', function (): Closure {
        $path = getenv('PATH');
        putenv('PATH='.sys_get_temp_dir().'/molly-no-git-'.Str::uuid());

        return fn () => putenv('PATH='.$path);
    }],
    'sandbox unavailable' => ['SANDBOX_UNAVAILABLE', function (): Closure {
        $sandbox = new Sandbox;
        (fn () => $this->available = false)->call($sandbox);
        app()->instance(Sandbox::class, $sandbox);
        config(['molly.sandbox.allow_unsafe' => false]);

        return fn () => null;
    }],
    'protected test missing' => ['PROTECTED_TEST_MISSING', function ($test): Closure {
        $contents = File::get($test->workspace.'/tests/GreetingTest.php');
        File::delete($test->workspace.'/tests/GreetingTest.php');

        return fn () => File::put($test->workspace.'/tests/GreetingTest.php', $contents);
    }],
]);

it('names an unknown Orb before it checks the sandbox', function (): void {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php', nickname: 'orb-check');
    $sandbox = new Sandbox;
    (fn () => $this->available = false)->call($sandbox);
    app()->instance(Sandbox::class, $sandbox);
    config(['molly.sandbox.allow_unsafe' => false]);

    $exit = Artisan::call('molly:start', ['task' => 'orb-check', '--orb' => 'no-such-orb', '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $refused = app(RecordLifecycleEvent::class)->load($this->workspace)->latestOf($task->id, LifecycleEventType::StartRefused);

    expect($exit)->toBe(1)
        ->and($result['report']['error'])->toStartWith('ORB_NOT_FOUND: No registered Orb has the name or ID no-such-orb.')
        ->and($refused->payload['code'])->toBe('ORB_NOT_FOUND')
        ->and($task->fresh()->only(['status', 'attempt_number', 'worker_id']))->toBe(['status' => 'pending', 'attempt_number' => 0, 'worker_id' => null])
        ->and($task->runs()->count())->toBe(0);

    // The sandbox check itself is unchanged: without an Orb the same start is refused by it.
    expect(Artisan::call('molly:start', ['task' => 'orb-check', '--json' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['report']['error'])->toStartWith('SANDBOX_UNAVAILABLE:');
});

it('keeps a start refused by a busy workspace pending', function (): void {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');

    (new Workspace($this->workspace))->exclusively(function () use ($task): void {
        expect(fn () => app(StartTask::class)->handle($task->id))->toThrow(RuntimeException::class, 'WORKSPACE_BUSY:');
    });

    expect($task->fresh()->status)->toBe('pending')
        ->and($task->fresh()->attempt_number)->toBe(0)
        ->and(lifecycleTypes($this->workspace, $task->id))->toBe(['created', 'start_refused']);
});

it('starts normally once the refused precondition is fixed', function (): void {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $path = getenv('PATH');
    putenv('PATH='.sys_get_temp_dir().'/molly-no-git-'.Str::uuid());
    try {
        expect(fn () => app(StartTask::class)->handle($task->id))->toThrow(RuntimeException::class, 'GIT_MISSING:');
    } finally {
        putenv('PATH='.$path);
    }

    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true]);
    $this->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle')->once()->andReturn([
        'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']),
        'findings' => [],
    ]);
    ChangeWriter::fake([['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]]])->preventStrayPrompts();
    config(['molly.parallel_checks' => false]);

    $run = app(StartTask::class)->handle($task->id);

    expect($run->status)->toBe('completed')
        ->and($task->fresh()->status)->toBe('completed')
        ->and($task->fresh()->attempt_number)->toBe(1)
        ->and($task->fresh()->attemptsUsed())->toBe(1)
        ->and(array_slice(lifecycleTypes($this->workspace, $task->id), 0, 4))->toBe(['created', 'start_refused', 'workspace_prepared', 'dispatch_requested']);
});

it('keeps a refused retry failed without scheduling it', function (): void {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $task->update(['status' => 'failed', 'attempt_number' => 1]);
    Run::create(['task_id' => $task->id, 'prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => []]);
    $path = getenv('PATH');
    putenv('PATH='.sys_get_temp_dir().'/molly-no-git-'.Str::uuid());

    try {
        expect(fn () => app(RetryTask::class)->handle($task->id))->toThrow(RuntimeException::class, 'GIT_MISSING:');
    } finally {
        putenv('PATH='.$path);
    }

    $refused = app(RecordLifecycleEvent::class)->load($this->workspace)->latestOf($task->id, LifecycleEventType::StartRefused);
    expect($task->fresh()->status)->toBe('failed')
        ->and($task->fresh()->attempt_number)->toBe(1)
        ->and($task->runs()->count())->toBe(1)
        ->and(lifecycleTypes($this->workspace, $task->id))->toBe(['created', 'start_refused'])
        ->and($refused->payload['retry'])->toBeTrue();
});

it('leaves an abandoned task failed and retryable when its retry is refused', function (): void {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $task->update([
        'status' => 'running',
        'attempt_number' => 1,
        'worker_id' => 'dead-worker',
        'claimed_at' => now()->subMinutes(30),
        'heartbeat_at' => now()->subMinutes(12),
        'lease_expires_at' => now()->subMinutes(10),
    ]);
    $safe = app(Sandbox::class);
    $allowUnsafe = config('molly.sandbox.allow_unsafe');
    $unsafe = new Sandbox;
    (fn () => $this->available = false)->call($unsafe);
    app()->instance(Sandbox::class, $unsafe);
    config(['molly.sandbox.allow_unsafe' => false]);

    expect(fn () => app(RetryTask::class)->handle($task->id))->toThrow(RuntimeException::class, 'SANDBOX_UNAVAILABLE:');

    $task->refresh();
    $status = app(ShowRuntimeStatus::class)->handle($this->workspace);
    expect($task->status)->toBe('failed')
        ->and($task->attempt_number)->toBe(1)
        ->and($task->worker_id)->toBeNull()
        ->and($task->lease_expires_at)->toBeNull()
        ->and($task->heartbeat_at)->toBeNull()
        ->and($task->runs()->count())->toBe(0)
        ->and($status['tasks']['running'])->toBe([])
        ->and(app(RecordLifecycleEvent::class)->load($this->workspace)->latestOf($task->id, LifecycleEventType::StartRefused)->payload['code'])->toBe('SANDBOX_UNAVAILABLE');

    expect(fn () => app(StartTask::class)->handle($task->id))->toThrow(RuntimeException::class, 'TASK_NOT_PENDING:');
    $pending = app(CreateTask::class)->handle('Return Hello again.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    expect(fn () => app(StartTask::class)->handle($pending->id))->toThrow(RuntimeException::class, 'SANDBOX_UNAVAILABLE:')
        ->and($pending->fresh()->only(['status', 'attempt_number', 'worker_id', 'claimed_at', 'lease_expires_at']))
        ->toBe(['status' => 'pending', 'attempt_number' => 0, 'worker_id' => null, 'claimed_at' => null, 'lease_expires_at' => null]);

    app()->instance(Sandbox::class, $safe);
    config(['molly.sandbox.allow_unsafe' => $allowUnsafe]);
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true]);
    $this->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle')->once()->andReturn([
        'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']),
        'findings' => [],
    ]);
    ChangeWriter::fake([['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]]])->preventStrayPrompts();
    config(['molly.parallel_checks' => false]);

    $run = app(RetryTask::class)->handle($task->id);

    expect($run->status)->toBe('completed')
        ->and($task->fresh()->status)->toBe('completed')
        ->and($task->fresh()->attempt_number)->toBe(2)
        ->and($task->fresh()->worker_id)->toBeNull();
});

it('shows an abandoned task as failed in molly:task after a refused molly:retry recovers it', function (): void {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    app(RecordLifecycleEvent::class)->handle($this->workspace, LifecycleEventType::AgentStarted, $task->id);
    $task->update([
        'status' => 'running',
        'attempt_number' => 1,
        'worker_id' => 'dead-worker',
        'claimed_at' => now()->subMinutes(30),
        'heartbeat_at' => now()->subMinutes(12),
        'lease_expires_at' => now()->subMinutes(10),
    ]);
    $unsafe = new Sandbox;
    (fn () => $this->available = false)->call($unsafe);
    app()->instance(Sandbox::class, $unsafe);
    config(['molly.sandbox.allow_unsafe' => false]);

    expect(Artisan::call('molly:retry', ['task' => $task->id, '--json' => true]))->toBe(1)
        ->and(Artisan::call('molly:task', ['task' => $task->id, '--json' => true]))->toBe(0);
    $shown = json_decode(Artisan::output(), true);

    $recovered = app(RecordLifecycleEvent::class)->load($this->workspace)->latestOf($task->id, LifecycleEventType::Failed);
    expect($task->fresh()->status)->toBe('failed')
        ->and($shown['status'])->toBe('failed')
        ->and($shown['display_status'])->toBe('failed')
        ->and($recovered->payload)->toMatchArray(['reason' => 'lease_expired', 'worker_id' => 'dead-worker'])
        ->and(lifecycleTypes($this->workspace, $task->id))->toBe(['created', 'agent_started', 'failed', 'start_refused']);

    expect(Artisan::call('molly:retry', ['task' => $task->id, '--json' => true]))->toBe(1)
        ->and(lifecycleTypes($this->workspace, $task->id))->toBe(['created', 'agent_started', 'failed', 'start_refused', 'start_refused']);
});

it('records no lease recovery when the workspace has no lifecycle log', function (): void {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    File::delete($this->workspace.'/.molly/lifecycle.jsonl');
    $task->update(['status' => 'running', 'attempt_number' => 1, 'worker_id' => 'dead-worker', 'lease_expires_at' => now()->subMinutes(10)]);

    app(LocalAgentBus::class)->recoverIfAbandoned($task);

    expect($task->fresh()->status)->toBe('failed')
        ->and(is_file($this->workspace.'/.molly/lifecycle.jsonl'))->toBeFalse();
});
