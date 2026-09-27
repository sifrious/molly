<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\AgentBus\LocalAgentBus;
use Sifrious\Molly\Models\Task;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-lease-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($this->workspace);
    commitGitWorkspace($this->workspace);
    config(['molly.agent_bus.lease_seconds' => 30, 'molly.timeout' => 10, 'molly.test_timeout' => 10]);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('keeps the lease through a run longer than lease_seconds and refuses a second claimer', function () {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $bus = app(LocalAgentBus::class);
    $refusals = [];
    $elapsed = 0;
    // Each phase takes 35 seconds, longer than the 30 second lease. Before
    // returning, a second worker tries to recover and claim the live task.
    $slowPhase = function () use ($task, $bus, &$refusals, &$elapsed): void {
        $this->travel(35)->seconds();
        $elapsed += 35;
        expect($bus->recoverAbandoned())->toBe([]);
        foreach ([false, true] as $retry) {
            try {
                $bus->claim($task->id, 'second-worker', $retry);
            } catch (RuntimeException $exception) {
                $refusals[] = strtok($exception->getMessage(), ':');
            }
        }
    };

    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturnUsing(function () use ($slowPhase) {
        $slowPhase();

        return ['status' => 'ok', 'probes' => []];
    });
    $this->mock(GenerateChanges::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($slowPhase) {
        $slowPhase();

        return ['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]];
    });
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($slowPhase) {
        $slowPhase();

        return ['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true];
    });
    $this->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle')->once()->andReturnUsing(function () use ($slowPhase) {
        $slowPhase();

        return ['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']), 'findings' => []];
    });

    $run = app(StartTask::class)->handle($task->id);

    expect($elapsed)->toBeGreaterThan(4 * 30)
        ->and($run->status)->toBe('completed')
        ->and($refusals)->toHaveCount(10)
        ->and(array_unique($refusals))->toEqualCanonicalizing(['TASK_NOT_PENDING', 'TASK_NOT_RETRYABLE'])
        ->and($task->fresh()->status)->toBe('completed')
        ->and($task->fresh()->attempt_number)->toBe(1)
        ->and($task->runs()->count())->toBe(1);
});

it('renews a run lease long enough to outlast the longest bounded step', function () {
    $bus = app(LocalAgentBus::class);

    expect($bus->runLeaseSeconds())->toBe(40);

    config(['molly.timeout' => 180, 'molly.test_timeout' => 120, 'molly.agent_bus.lease_seconds' => 120]);
    expect($bus->runLeaseSeconds())->toBe(210);

    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $bus->claim($task->id, 'worker-a');
    $renewed = $bus->heartbeat($task->id, 'worker-a', $bus->runLeaseSeconds());

    expect((int) round($renewed->heartbeat_at->diffInSeconds($renewed->lease_expires_at)))->toBe(210);
});

it('fails the run when its lease was lost before a checkpoint', function () {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->once()->andReturnUsing(function () use ($task) {
        Task::whereKey($task->id)->update(['worker_id' => 'another-worker']);

        return ['status' => 'ok', 'probes' => []];
    });
    $this->mock(GenerateChanges::class)->shouldNotReceive('handle');

    $run = app(StartTask::class)->handle($task->id);

    expect($run->status)->toBe('failed')
        ->and($run->report['error'])->toStartWith('TASK_LEASE_INVALID');
});
