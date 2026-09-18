<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\AcceptHandoff;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\HandOffTask;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Contracts\DisplayStatus;
use Sifrious\Molly\Contracts\HandoffEnvelope;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-handoff-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($this->workspace);
    $this->task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $this->run = $this->task->runs()->create([
        'prompt' => $this->task->prompt,
        'workspace' => $this->task->workspace,
        'status' => 'failed',
        'report' => ['verification' => ['status' => 'failed', 'reason' => 'tests_failed'], 'review' => ['findings' => [['problem' => 'Keep the greeting helper small.']]]],
    ]);
    $this->sender = (string) Str::uuid();
    $this->recipient = (string) Str::uuid();
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('hands a locked implementation envelope to a different Bloom workspace', function () {
    $handoff = app(HandOffTask::class)->handle($this->task->id, $this->sender, $this->recipient, 'implement', 'Implement the locked greeting test.');

    expect($handoff)->toBeInstanceOf(HandoffEnvelope::class)
        ->and($handoff->allowedPaths)->toBe(['app/Greeting.php'])
        ->and($handoff->protectedTests)->toBe(['tests/GreetingTest.php'])
        ->and($handoff->sourceRunId)->toBe($this->run->id)
        ->and($handoff->priorDiagnostics)->toContain('pest:failed');

    $accepted = app(AcceptHandoff::class)->handle($handoff, $this->task->id, $this->recipient);
    expect($accepted->handoffId)->toBe($handoff->handoffId);

    $log = app(RecordLifecycleEvent::class)->load($this->workspace);
    expect($log->displayStatus($this->task->id))->toBe(DisplayStatus::Running)
        ->and(array_map(fn ($event) => $event->type()?->value, $log->events($this->task->id)))->toContain('created', 'handed_off', 'recovered');
});

it('ignores a duplicate handoff event id', function () {
    $id = (string) Str::uuid();
    app(HandOffTask::class)->handle($this->task->id, $this->sender, $this->recipient, 'implement', 'Implement the locked greeting test.', ['handoff_id' => $id]);
    app(HandOffTask::class)->handle($this->task->id, $this->sender, $this->recipient, 'implement', 'Implement the locked greeting test.', ['handoff_id' => $id]);

    $types = array_map(fn ($event) => $event->type()?->value, app(RecordLifecycleEvent::class)->load($this->workspace)->events($this->task->id));
    expect($types)->toBe(['created', 'handed_off']);
});

it('rejects a handoff that would merge or edit the protected test', function () {
    expect(fn () => app(HandOffTask::class)->handle($this->task->id, $this->sender, $this->recipient, 'merge', 'Merge the change.'))
        ->toThrow(RuntimeException::class, 'HANDOFF_PRIVILEGE');

    $writable = app(CreateTask::class)->handle('Author the test.', $this->workspace, [], 'tests/GreetingTest.php', allowTestEdits: true);
    $writable->runs()->create(['prompt' => $writable->prompt, 'workspace' => $writable->workspace, 'status' => 'completed', 'report' => []]);
    expect(fn () => app(HandOffTask::class)->handle($writable->id, $this->sender, $this->recipient, 'implement', 'Change the test.'))
        ->toThrow(RuntimeException::class, 'HANDOFF_TEST_WRITABLE');
});

it('rejects a recipient that widens file scope', function () {
    $handoff = app(HandOffTask::class)->handle($this->task->id, $this->sender, $this->recipient, 'implement', 'Implement the locked greeting test.');
    $this->task->update(['paths' => ['app/Greeting.php', 'app/Secret.php']]);

    expect(fn () => app(AcceptHandoff::class)->handle($handoff, $this->task->id, $this->recipient))
        ->toThrow(RuntimeException::class, 'HANDOFF_SCOPE_WIDENED');
});
