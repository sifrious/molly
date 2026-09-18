<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Actions\StopTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Contracts\DispatchDecision;
use Sifrious\Molly\Contracts\DisplayStatus;
use Sifrious\Molly\Contracts\LifecycleEventType;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-lifecycle-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($this->workspace);
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
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1]);
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
