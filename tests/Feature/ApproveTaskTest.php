<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\ApproveTask;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Contracts\DisplayStatus;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-approve-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($this->workspace);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('refuses approval before required checks pass', function () {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');

    expect(fn () => app(ApproveTask::class)->handle($task->id, true))
        ->toThrow(RuntimeException::class, 'APPROVAL_NOT_REQUESTED');
});

it('records human approval without opening a pull request', function () {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1]);
    $this->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle')->once()->andReturn([
        'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']),
        'findings' => [],
    ]);
    ChangeWriter::fake([['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]]])->preventStrayPrompts();
    config(['molly.parallel_checks' => false]);
    app(StartTask::class)->handle($task->id);

    expect(fn () => app(ApproveTask::class)->handle($task->id, false))
        ->toThrow(RuntimeException::class, 'APPROVAL_UNCONFIRMED');

    $result = app(ApproveTask::class)->handle($task->id, true);
    $log = app(RecordLifecycleEvent::class)->load($this->workspace);

    expect($result['approved'])->toBeTrue()
        ->and($result['display_status'])->toBe(DisplayStatus::Approved->value)
        ->and($log->displayStatus($task->id))->toBe(DisplayStatus::Approved)
        ->and(array_map(fn ($event) => $event->type()?->value, $log->events($task->id)))->toContain('approval_requested', 'approval_resolved')
        ->and($log->events($task->id)[array_key_last($log->events($task->id))]->payload['pull_request_opened'] ?? true)->toBeFalse();

    expect(Artisan::call('molly:approve', ['task' => $task->id, '--approve' => true, '--json' => true]))->toBe(0);
});
