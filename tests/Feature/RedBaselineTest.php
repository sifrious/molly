<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\LockProtectedTest;
use Sifrious\Molly\Actions\RecordRedBaseline;
use Sifrious\Molly\Actions\RecordVerificationReceipts;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Models\Task;

function redBaselineWorkspace(bool $realPest = true): string
{
    // Pest derives a namespace from the test path, and some system temp paths contain segments PHP rejects.
    $workspace = '/tmp/molly-red-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($workspace.'/app');
    File::ensureDirectoryExists($workspace.'/tests');
    File::put($workspace.'/app/Greeting.php', '<?php');
    if ($realPest) {
        symlink(dirname(__DIR__, 2).'/vendor', $workspace.'/vendor');
        File::put($workspace.'/phpunit.xml', '<?xml version="1.0" encoding="UTF-8"?><phpunit bootstrap="vendor/autoload.php"><testsuites><testsuite name="Workspace"><directory>tests</directory></testsuite></testsuites></phpunit>');
    } else {
        File::ensureDirectoryExists($workspace.'/vendor/bin');
        File::put($workspace.'/vendor/bin/pest', '<?php');
    }
    commitGitWorkspace($workspace);

    return $workspace;
}

function authoredAndLocked(string $workspace, string $test, bool $requireRedBaseline = true): array
{
    $task = app(CreateTask::class)->handle('Author the greeting test.', $workspace, [], 'tests/GreetingTest.php', allowTestEdits: true, requireRedBaseline: $requireRedBaseline);
    File::put($workspace.'/tests/GreetingTest.php', $test);
    $result = app(LockProtectedTest::class)->handle($task->id, true, ['app/Greeting.php']);

    return [$task->fresh(), $result];
}

afterEach(function () {
    if (isset($this->workspace)) {
        File::deleteDirectory($this->workspace);
    }
});

it('records a missing behavior RED baseline when the locked test fails on an unbuilt class', function () {
    $this->workspace = redBaselineWorkspace();

    [$task, $result] = authoredAndLocked($this->workspace, '<?php it("greets", function () { expect(App\\Greeting::hello())->toBe("Hello"); });');
    $baseline = $task->source['test_lock']['red_baseline'];

    expect($result['red_baseline']['classification'])->toBe('missing_behavior')
        ->and($baseline['classification'])->toBe('missing_behavior')
        ->and($baseline['tests'])->toBe(1)
        ->and($baseline['errors'] + $baseline['failures'])->toBe(1)
        ->and($baseline['junit_digest'])->toMatch('/\A[a-f0-9]{64}\z/')
        ->and($baseline['junit_digest'])->toBe(hash_file('sha256', $baseline['junit']))
        ->and($baseline['test_digest'])->toBe($task->test_digest)
        ->and($baseline['failing_tests'][0])->toMatchArray(['kind' => 'error', 'type' => 'Error'])
        ->and($task->redBaselineError())->toBeNull();

    Artisan::call('molly:task', ['task' => $task->id, '--json' => true]);
    $json = json_decode(Artisan::output(), true);
    expect($json['task']['source']['test_lock']['red_baseline']['classification'])->toBe('missing_behavior');
});

it('refuses implementation after a bootstrap error and accepts a repaired, relocked test', function () {
    $this->workspace = redBaselineWorkspace();

    [$task, $result] = authoredAndLocked($this->workspace, '<?php it("greets", function () { expect(');

    expect($result['red_baseline']['classification'])->toBe('bootstrap_error')
        ->and($task->redBaselineError())->toStartWith('RED_BASELINE_INVALID');

    $this->mock(GenerateChanges::class)->shouldNotReceive('handle');
    expect(fn () => app(StartTask::class)->handle($task->id))->toThrow(RuntimeException::class, 'RED_BASELINE_INVALID');
    expect($task->fresh()->status)->toBe('pending')->and($task->runs()->count())->toBe(0);

    $broken = $task->test_digest;
    File::put($this->workspace.'/tests/GreetingTest.php', '<?php it("greets", function () { expect(App\\Greeting::hello())->toBe("Hello"); });');
    $relock = app(LockProtectedTest::class)->handle($task->id, true, reason: 'Repair the parse error in the authored test.');
    $task->refresh();

    expect($relock['locked'])->toBeTrue()
        ->and($relock['before_digest'])->toBe($broken)
        ->and($task->test_digest)->toBe($relock['after_digest'])
        ->and($task->source['test_lock']['reason'])->toBe('Repair the parse error in the authored test.')
        ->and($relock['red_baseline']['classification'])->toBe('missing_behavior')
        ->and($task->redBaselineError())->toBeNull();

    $again = app(LockProtectedTest::class)->handle($task->id, true);
    expect($again['locked'])->toBeFalse()
        ->and($again['red_baseline']['recorded_at'])->toBe($relock['red_baseline']['recorded_at']);
});

it('marks a locked test that already passes as an invalid RED baseline', function () {
    $this->workspace = redBaselineWorkspace();

    [$task, $result] = authoredAndLocked($this->workspace, '<?php it("greets", fn () => expect(true)->toBeTrue());');

    expect($result['red_baseline']['classification'])->toBe('already_passing')
        ->and($task->redBaselineError())->toStartWith('RED_BASELINE_INVALID');
});

it('refuses a locked task without a baseline and allows an explicit opt out', function () {
    $this->workspace = redBaselineWorkspace(realPest: false);
    $task = app(CreateTask::class)->handle('Author the greeting test.', $this->workspace, [], 'tests/GreetingTest.php', allowTestEdits: true);
    writeProtectedTest($this->workspace);
    $task->update(['allow_test_edits' => false, 'test_digest' => hash_file('sha256', $this->workspace.'/tests/GreetingTest.php'), 'source' => ['test_lock' => ['approved_by' => 'human']]]);

    expect($task->fresh()->redBaselineError())->toStartWith('RED_BASELINE_MISSING');
    expect(fn () => app(StartTask::class)->handle($task->id))->toThrow(RuntimeException::class, 'RED_BASELINE_MISSING');

    $optedOut = app(CreateTask::class)->handle('Author the greeting test.', $this->workspace, [], 'tests/GreetingTest.php', allowTestEdits: true, requireRedBaseline: false);
    $optedOut->update(['allow_test_edits' => false, 'source' => [...$optedOut->source, 'test_lock' => ['approved_by' => 'human']]]);

    expect($optedOut->fresh()->source['red_baseline_required'])->toBeFalse()
        ->and($optedOut->fresh()->redBaselineError())->toBeNull();

    $handWritten = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    expect($handWritten->redBaselineError())->toBeNull();
});

it('classifies JUnit failures as missing behavior or bootstrap errors', function (string $xml, string $classification) {
    $this->workspace = redBaselineWorkspace(realPest: false);
    writeProtectedTest($this->workspace);
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    Process::fake(function (PendingProcess $process) use ($xml) {
        file_put_contents($process->command[array_search('--log-junit', $process->command, true) + 1], $xml);

        return Process::result(exitCode: 1);
    });

    expect(app(RecordRedBaseline::class)->handle($task)['classification'])->toBe($classification);
})->with([
    'assertion failure' => ['<testsuite tests="1" file="tests/GreetingTest.php"><testcase name="greets" file="tests/GreetingTest.php::greets" assertions="1"><failure type="PHPUnit\\Framework\\ExpectationFailedException">Expected response status code [200] but received 404.</failure></testcase></testsuite>', 'missing_behavior'],
    'missing application class' => ['<testsuite tests="1" file="tests/GreetingTest.php"><testcase name="greets" file="tests/GreetingTest.php::greets" assertions="0"><error type="Error">Class "App\\Livewire\\Counter" not found</error></testcase></testsuite>', 'missing_behavior'],
    'missing test base class' => ['<testsuite tests="1" file="tests/GreetingTest.php"><testcase name="greets" file="tests/GreetingTest.php::greets" assertions="0"><error type="Error">Class "Tests\\TestCase" not found</error></testcase></testsuite>', 'bootstrap_error'],
    'parse error' => ['<testsuite tests="1" file="tests/GreetingTest.php"><testcase name="greets" file="tests/GreetingTest.php::greets" assertions="0"><error type="ParseError">unexpected end of file</error></testcase></testsuite>', 'bootstrap_error'],
    'zero tests' => ['<testsuite tests="0"/>', 'bootstrap_error'],
    'missing report' => ['', 'bootstrap_error'],
]);

it('carries the RED baseline into each verification receipt', function () {
    $this->workspace = redBaselineWorkspace(realPest: false);
    $runId = (string) Str::uuid();
    $report = [
        'red_baseline' => ['classification' => 'missing_behavior', 'reason' => 'tests_failed', 'tests' => 1, 'failures' => 1, 'errors' => 0, 'junit_digest' => str_repeat('a', 64), 'test_digest' => str_repeat('b', 64), 'recorded_at' => '2026-09-27T00:00:00+00:00', 'junit' => '/tmp/red.xml'],
        'verification' => ['status' => 'failed', 'tests' => 1, 'failures' => 1],
        'verification_outcomes' => ['pest' => ['state' => 'FAIL', 'policy' => 'required', 'failure_action' => 'retry']],
    ];

    $receipts = app(RecordVerificationReceipts::class)->handle($this->workspace, $runId, $report);
    $onDisk = json_decode(File::get($receipts[0]['path']), true);
    $reloaded = app(RecordVerificationReceipts::class)->handle($this->workspace, $runId, $report);

    expect($receipts[0]['context']['red_baseline']['classification'])->toBe('missing_behavior')
        ->and($receipts[0]['context']['red_baseline'])->not->toHaveKey('junit')
        ->and($onDisk['context']['red_baseline']['junit_digest'])->toBe(str_repeat('a', 64))
        ->and($reloaded[0]['context'])->toBe($receipts[0]['context']);
});

it('refuses a direct run of a locked task without a usable baseline', function () {
    $this->workspace = redBaselineWorkspace(realPest: false);
    writeProtectedTest($this->workspace);
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $task->update(['source' => ['test_lock' => ['approved_by' => 'human', 'red_baseline' => ['classification' => 'bootstrap_error', 'reason' => 'no_tests', 'test_digest' => $task->test_digest]]]]);

    expect(fn () => app(RunTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php', taskId: $task->id))
        ->toThrow(RuntimeException::class, 'RED_BASELINE_INVALID: The locked Pest test run was classified as bootstrap_error (no_tests)');
    expect(Task::find($task->id)->runs()->count())->toBe(0);
});
