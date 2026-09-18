<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-protected-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    $this->digest = writeProtectedTest($this->workspace);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('keeps the required Pest test out of the writer scope by default', function () {
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php', 'tests/GreetingTest.php'], 'tests/GreetingTest.php');

    expect($task->paths)->toBe(['app/Greeting.php'])
        ->and($task->allow_test_edits)->toBeFalse()
        ->and($task->test_digest)->toBe($this->digest);
});

it('rejects a proposal that changes the protected test before applying it', function () {
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->once()->andReturn(['status' => 'ok', 'probes' => []]);
    ChangeWriter::fake([[
        'summary' => 'Weaken the test.',
        'files' => [['path' => 'tests/GreetingTest.php', 'content' => '<?php it("always passes", fn () => expect(true)->toBeTrue());']],
    ]])->preventStrayPrompts();

    $run = app(RunTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');

    expect($run->status)->toBe('failed')
        ->and($run->report['error'])->toStartWith('PROTECTED_TEST_CHANGED:')
        ->and(File::get($this->workspace.'/tests/GreetingTest.php'))->toBe('<?php it("exists", fn () => expect(true)->toBeTrue());')
        ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe('<?php return null;');
});

it('fails when the protected test changes on disk during the run', function () {
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->once()->andReturnUsing(function () {
        File::put($this->workspace.'/tests/GreetingTest.php', '<?php it("was mutated", fn () => expect(true)->toBeTrue());');

        return ['status' => 'ok', 'probes' => []];
    });
    ChangeWriter::fake([[
        'summary' => 'Return Hello.',
        'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']],
    ]])->preventStrayPrompts();

    $run = app(RunTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');

    expect($run->status)->toBe('failed')
        ->and($run->report['error'])->toStartWith('PROTECTED_TEST_CHANGED:');
});

it('records who opted into the weaker test-editing trust model', function () {
    $task = app(CreateTask::class)->handle('Author the greeting test.', $this->workspace, [], 'tests/GreetingTest.php', allowTestEdits: true);

    expect($task->allow_test_edits)->toBeTrue()
        ->and($task->paths)->toBe(['tests/GreetingTest.php'])
        ->and($task->test_digest)->toBe($this->digest)
        ->and(File::get($task->journal_status['journal_path']))->toContain('writable for this task', $this->digest);
});

it('requires an existing approved test before implementation starts', function () {
    File::delete($this->workspace.'/tests/GreetingTest.php');

    expect(fn () => app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php'))
        ->toThrow(RuntimeException::class, 'PROTECTED_TEST_MISSING');
    expect(Task::count())->toBe(0);
});

it('rejects a missing protected test at apply time', function () {
    $workspace = new Workspace($this->workspace);

    expect(fn () => $workspace->assertProtectedTestUnchanged('tests/GreetingTest.php', 'deadbeef'))
        ->toThrow(RuntimeException::class, 'PROTECTED_TEST_CHANGED');
});
