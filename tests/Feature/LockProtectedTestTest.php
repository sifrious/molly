<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\LockProtectedTest;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Contracts\DisplayStatus;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Mcp\MollyServer;
use Sifrious\Molly\Mcp\MollyTask;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-lock-test-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    $this->before = writeProtectedTest($this->workspace);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('locks a test-authoring task and drops the Pest file from the writer scope', function () {
    $task = app(CreateTask::class)->handle('Author the greeting test.', $this->workspace, [], 'tests/GreetingTest.php', allowTestEdits: true);
    $after = writeProtectedTest($this->workspace, contents: '<?php it("returns Hello", fn () => expect(true)->toBeTrue());');

    expect(fn () => app(LockProtectedTest::class)->handle($task->id, false))
        ->toThrow(RuntimeException::class, 'TEST_LOCK_UNCONFIRMED');

    $result = app(LockProtectedTest::class)->handle(
        $task->id,
        true,
        ['app/Greeting.php'],
        'The greeting test now names the required Hello return.',
    );
    $task->refresh();
    $log = app(RecordLifecycleEvent::class)->load($this->workspace);
    $journal = File::get($task->journal_status['journal_path']);

    expect($result['locked'])->toBeTrue()
        ->and($result['allow_test_edits'])->toBeFalse()
        ->and($result['before_digest'])->toBe($this->before)
        ->and($result['after_digest'])->toBe($after)
        ->and($task->allow_test_edits)->toBeFalse()
        ->and($task->paths)->toBe(['app/Greeting.php'])
        ->and($task->test_digest)->toBe($after)
        ->and($task->status)->toBe('pending')
        ->and($task->source['test_lock']['approved_by'])->toBe('human')
        ->and($task->source['test_lock']['reason'])->toBe('The greeting test now names the required Hello return.')
        ->and($log->displayStatus($task->id))->toBe(DisplayStatus::Pending)
        ->and($log->latestOf($task->id, LifecycleEventType::TestLocked)?->payload['after_digest'])->toBe($after)
        ->and($journal)->toContain('protected', $after, 'human', 'The greeting test now names the required Hello return\\.');

    $again = app(LockProtectedTest::class)->handle($task->id, true);
    expect($again['locked'])->toBeFalse()
        ->and($again['after_digest'])->toBe($after);

    expect(Artisan::call('molly:lock-test', [
        'task' => $task->id,
        '--approve' => true,
        '--file' => ['app/Greeting.php'],
        '--json' => true,
    ]))->toBe(0);
});

it('refuses to lock a missing Pest file', function () {
    $task = app(CreateTask::class)->handle('Author the greeting test.', $this->workspace, [], 'tests/GreetingTest.php', allowTestEdits: true);
    File::delete($this->workspace.'/tests/GreetingTest.php');

    expect(fn () => app(LockProtectedTest::class)->handle($task->id, true, ['app/Greeting.php']))
        ->toThrow(RuntimeException::class, 'PROTECTED_TEST_MISSING');
});

it('locks a Pest test through MCP without starting an agent', function () {
    $task = app(CreateTask::class)->handle('Author the greeting test.', $this->workspace, [], 'tests/GreetingTest.php', allowTestEdits: true);
    $after = writeProtectedTest($this->workspace, contents: '<?php it("returns Hello", fn () => expect(true)->toBeTrue());');

    MollyServer::tool(MollyTask::class, ['operation' => 'lock_test', 'id' => $task->id])->assertHasErrors(['approve']);
    MollyServer::tool(MollyTask::class, ['operation' => 'lock_test', 'id' => $task->id, 'approve' => false])
        ->assertHasErrors()->assertSee('TEST_LOCK_UNCONFIRMED');
    MollyServer::tool(MollyTask::class, [
        'operation' => 'lock_test',
        'id' => $task->id,
        'approve' => true,
        'paths' => ['app/Greeting.php'],
        'reason' => 'Lock the authored greeting test.',
    ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('locked', true)
        ->where('allow_test_edits', false)
        ->where('after_digest', $after)
        ->etc());

    expect($task->fresh()->allow_test_edits)->toBeFalse()
        ->and($task->fresh()->paths)->toBe(['app/Greeting.php']);
});
