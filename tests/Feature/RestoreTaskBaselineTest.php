<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RetryTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Agents\TarpitReviewer;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-baseline-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($this->workspace);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('restores the recorded baseline before a retry instead of starting from leftover edits', function () {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    File::put($this->workspace.'/app/Greeting.php', '<?php return "leftover";');
    $task->update(['status' => 'failed']);
    $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => ['error' => 'TESTS_FAILED']]);

    $checks = [];
    foreach (range('A', 'G') as $code) {
        $checks[$code] = ['status' => 'clean', 'evidence' => 'No finding.'];
    }
    TarpitReviewer::fake([['checks' => $checks, 'findings' => []]])->preventStrayPrompts();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true]);
    $this->mock(GenerateChanges::class)->shouldReceive('handle')->once()->andReturnUsing(function () {
        expect(File::get($this->workspace.'/app/Greeting.php'))->toBe('<?php return null;');

        return ['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]];
    });

    $run = app(RetryTask::class)->handle($task->id);

    expect($run->status)->toBe('completed')
        ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe('<?php return "Hello";');
});

it('keeps file modes when it restores a baseline and applies a proposal', function () {
    chmod($this->workspace.'/app/Greeting.php', 0644);
    chmod($this->workspace.'/tests/GreetingTest.php', 0644);
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    File::put($this->workspace.'/app/Greeting.php', '<?php return "leftover";');
    $task->update(['status' => 'failed']);
    $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => ['error' => 'TESTS_FAILED']]);

    $checks = [];
    foreach (range('A', 'G') as $code) {
        $checks[$code] = ['status' => 'clean', 'evidence' => 'No finding.'];
    }
    TarpitReviewer::fake([['checks' => $checks, 'findings' => []]])->preventStrayPrompts();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true]);
    $this->mock(GenerateChanges::class)->shouldReceive('handle')->once()->andReturn(['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]]);

    app(RetryTask::class)->handle($task->id);
    clearstatcache();

    expect(fileperms($this->workspace.'/app/Greeting.php') & 0777)->toBe(0644)
        ->and(fileperms($this->workspace.'/tests/GreetingTest.php') & 0777)->toBe(0644);
});
