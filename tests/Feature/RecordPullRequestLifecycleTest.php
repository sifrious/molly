<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\ApproveTask;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Actions\RecordMerged;
use Sifrious\Molly\Actions\RecordPullRequestOpened;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Contracts\DisplayStatus;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-pr-lifecycle-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($this->workspace);
    $this->prUrl = 'https://github.com/sifrious/molly/pull/12';
    $this->mergeSha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

function approvedTask(string $workspace, array $source = []): mixed
{
    $task = app(CreateTask::class)->handle('Return Hello.', $workspace, ['app/Greeting.php'], 'tests/GreetingTest.php', $source);
    test()->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn(['status' => 'ok', 'probes' => []]);
    test()->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1]);
    test()->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle')->once()->andReturn([
        'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']),
        'findings' => [],
    ]);
    ChangeWriter::fake([['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]]])->preventStrayPrompts();
    config(['molly.parallel_checks' => false]);
    app(StartTask::class)->handle($task->id);
    app(ApproveTask::class)->handle($task->id, true);

    return $task->fresh('runs');
}

it('refuses to record a pull request before human approval', function () {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');

    expect(fn () => app(RecordPullRequestOpened::class)->handle($task->id, true, $this->prUrl))
        ->toThrow(RuntimeException::class, 'PR_RECORD_NOT_APPROVED');
});

it('records an opened pull request without calling GitHub', function () {
    Process::preventStrayProcesses();
    $task = approvedTask($this->workspace);

    expect(fn () => app(RecordPullRequestOpened::class)->handle($task->id, false, $this->prUrl))
        ->toThrow(RuntimeException::class, 'PR_RECORD_UNCONFIRMED');

    $first = app(RecordPullRequestOpened::class)->handle($task->id, true, $this->prUrl);
    $second = app(RecordPullRequestOpened::class)->handle($task->id, true, $this->prUrl);
    $log = app(RecordLifecycleEvent::class)->load($this->workspace);

    expect($first['recorded'])->toBeTrue()
        ->and($first['opened'])->toBeFalse()
        ->and($first['display_status'])->toBe(DisplayStatus::Approved->value)
        ->and($second['recorded'])->toBeFalse()
        ->and($log->displayStatus($task->id))->toBe(DisplayStatus::Approved)
        ->and(array_map(fn ($event) => $event->type()?->value, $log->events($task->id)))->toContain('approval_resolved', 'pull_request_opened')
        ->and($task->fresh()->source['linked_pr']['url'])->toBe($this->prUrl);

    expect(fn () => app(RecordPullRequestOpened::class)->handle($task->id, true, 'https://github.com/sifrious/molly/pull/13'))
        ->toThrow(RuntimeException::class, 'PR_RECORD_CONFLICT');
    Process::assertNothingRan();
});

it('rejects a pull request from a different imported repository', function () {
    $task = approvedTask($this->workspace, [
        'repository' => 'sifrious/molly',
        'issue_number' => 42,
        'issue_url' => 'https://github.com/sifrious/molly/issues/42',
        'linked_pr' => null,
    ]);

    expect(fn () => app(RecordPullRequestOpened::class)->handle($task->id, true, 'https://github.com/other/repo/pull/12'))
        ->toThrow(RuntimeException::class, 'PR_RECORD_REPOSITORY');
});

it('records a merge SHA only after an opened pull request', function () {
    Process::preventStrayProcesses();
    $task = approvedTask($this->workspace);

    expect(fn () => app(RecordMerged::class)->handle($task->id, true, $this->mergeSha))
        ->toThrow(RuntimeException::class, 'MERGE_RECORD_PR_MISSING');

    app(RecordPullRequestOpened::class)->handle($task->id, true, $this->prUrl);

    expect(fn () => app(RecordMerged::class)->handle($task->id, false, $this->mergeSha))
        ->toThrow(RuntimeException::class, 'MERGE_RECORD_UNCONFIRMED');

    $first = app(RecordMerged::class)->handle($task->id, true, $this->mergeSha);
    $second = app(RecordMerged::class)->handle($task->id, true, $this->mergeSha);
    $log = app(RecordLifecycleEvent::class)->load($this->workspace);

    expect($first['recorded'])->toBeTrue()
        ->and($first['merged'])->toBeFalse()
        ->and($first['display_status'])->toBe(DisplayStatus::Merged->value)
        ->and($second['recorded'])->toBeFalse()
        ->and($log->displayStatus($task->id))->toBe(DisplayStatus::Merged)
        ->and($task->fresh()->source['linked_pr']['merge_sha'])->toBe($this->mergeSha);

    expect(fn () => app(RecordMerged::class)->handle($task->id, true, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'))
        ->toThrow(RuntimeException::class, 'MERGE_RECORD_CONFLICT');
    Process::assertNothingRan();
});

it('returns JSON from the pr-opened and merged commands', function () {
    $task = approvedTask($this->workspace);

    expect(Artisan::call('molly:pr-opened', ['task' => $task->id, '--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'])->toStartWith('PR_RECORD_UNCONFIRMED:');

    expect(Artisan::call('molly:pr-opened', [
        'task' => $task->id,
        '--url' => $this->prUrl,
        '--approve' => true,
        '--json' => true,
    ]))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['opened'])->toBeFalse();

    expect(Artisan::call('molly:merged', [
        'task' => $task->id,
        '--sha' => $this->mergeSha,
        '--approve' => true,
        '--json' => true,
    ]))->toBe(0);
    $merged = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($merged['merged'])->toBeFalse()
        ->and($merged['display_status'])->toBe(DisplayStatus::Merged->value);
});
