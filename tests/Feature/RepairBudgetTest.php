<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\FingerprintRunFailure;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\NameTask;
use Sifrious\Molly\Actions\RetryTask;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\AgentBus\LocalAgentBus;
use Sifrious\Molly\Models\Task;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-repair-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($this->workspace);
    config(['molly.max_attempts' => 10]);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

function failingPestReport(string $test, string $message, int $line = 12): array
{
    return [
        'verification' => [
            'status' => 'failed',
            'reason' => 'tests_failed',
            'output' => $message.' at line '.$line,
            'failing_tests' => [['name' => $test, 'file' => '/tmp/workspace-'.$line.'/tests/GreetingTest.php', 'kind' => 'failure', 'type' => 'PHPUnit\\Framework\\ExpectationFailedException', 'message' => $message]],
        ],
        'review' => ['findings' => [['code' => 'E', 'severity' => 'blocking', 'path' => 'app/Greeting.php', 'line' => $line, 'problem' => $message]]],
        'verification_outcomes' => [
            'pest' => ['state' => 'FAIL', 'policy' => 'required', 'failure_action' => 'retry'],
            'tarpit' => ['state' => 'FAIL', 'policy' => 'required', 'failure_action' => 'retry'],
        ],
    ];
}

it('keeps a fingerprint when only the failure wording, lines, or paths change', function () {
    $fingerprint = app(FingerprintRunFailure::class);

    $first = $fingerprint->handle(failingPestReport('it greets guests', 'Expected "Hello" but got null.'));
    $reworded = $fingerprint->handle(failingPestReport('it  greets   guests', 'Failed asserting that null is identical to "Hello".', 40));
    $otherTest = $fingerprint->handle(failingPestReport('it counts clicks', 'Expected "Hello" but got null.'));
    $crashed = $fingerprint->handle(['error' => 'GENERATION_INVALID: The model returned nothing useful.']);
    $crashedAgain = $fingerprint->handle(['error' => 'GENERATION_INVALID: The model proposed a file outside the allowed paths.']);

    expect($first['digest'])->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($reworded['digest'])->toBe($first['digest'])
        ->and($otherTest['digest'])->not->toBe($first['digest'])
        ->and($crashedAgain['digest'])->toBe($crashed['digest'])
        ->and($crashed['digest'])->not->toBe($first['digest'])
        ->and($first['inputs']['pest']['tests'])->toBe(['GreetingTest.php::it greets guests::failure'])
        ->and($first['inputs']['tarpit'])->toBe(['E:app/Greeting.php']);
});

it('refuses a fourth attempt at the same failure even after the task is renamed', function () {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $attempt = 0;
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(GenerateChanges::class)->shouldReceive('handle')->times(3)->andReturnUsing(function () use (&$attempt) {
        $attempt++;

        return ['summary' => 'Try '.$attempt, 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Try '.$attempt.'";']]];
    });
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->times(3)->andReturnUsing(fn () => [
        'status' => 'failed', 'reason' => 'tests_failed', 'tests' => 1, 'assertions' => 1, 'failures' => 1, 'identified_required_test' => true,
        'failing_tests' => [['name' => 'it greets', 'file' => 'tests/GreetingTest.php', 'kind' => 'failure', 'type' => 'Exception', 'message' => 'Got "Try '.$attempt.'" instead of "Hello".']],
    ]);
    $this->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle')->andReturn([
        'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']), 'findings' => [],
    ]);

    $runs = [app(StartTask::class)->handle($task->id), app(RetryTask::class)->handle($task->id)];
    app(NameTask::class)->handle($task->id, 'renamed-greeting');
    $runs[] = app(RetryTask::class)->handle('renamed-greeting');
    $digests = array_map(fn ($run) => $run->report['failure_fingerprint']['digest'], $runs);

    expect(array_unique($digests))->toHaveCount(1)
        ->and($task->fresh()->repeatedFailure())->toBe(['digest' => $digests[0], 'failures' => 3]);
    expect(fn () => app(RetryTask::class)->handle('renamed-greeting'))
        ->toThrow(RuntimeException::class, 'REPAIR_BUDGET_EXHAUSTED: The same failure has happened 3 times (fingerprint '.$digests[0].')');
    expect($task->fresh()->status)->toBe('failed')->and($task->runs()->count())->toBe(3);
});

it('counts each distinct failure separately and keeps the total cap', function () {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $fingerprint = app(FingerprintRunFailure::class);
    $fail = fn (string $test) => $task->runs()->create([
        'prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed',
        'report' => ['failure_fingerprint' => $fingerprint->handle(failingPestReport($test, 'Wrong.'))],
    ]);
    $bus = app(LocalAgentBus::class);
    Task::whereKey($task->id)->update(['status' => 'failed']);

    $fail('it greets');
    $fail('it greets');
    $fail('it counts');
    $fail('it greets');
    expect($task->fresh()->repeatedFailure()['failures'])->toBe(3)
        ->and(fn () => $bus->claim($task->id, 'worker', retry: true))->toThrow(RuntimeException::class, 'REPAIR_BUDGET_EXHAUSTED');

    config(['molly.repair.per_failure' => 4]);
    expect($bus->claim($task->id, 'worker', retry: true)->status)->toBe('running');

    Task::whereKey($task->id)->update(['status' => 'failed']);
    config(['molly.max_attempts' => 4]);
    expect(fn () => $bus->claim($task->id, 'worker', retry: true))->toThrow(RuntimeException::class, 'ATTEMPT_LIMIT_REACHED');

    config(['molly.max_attempts' => 10, 'molly.repair.per_failure' => 0]);
    expect(fn () => $bus->claim($task->id, 'worker', retry: true))->toThrow(RuntimeException::class, 'REPAIR_BUDGET_INVALID');
});
