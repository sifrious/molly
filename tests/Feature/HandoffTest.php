<?php

use Illuminate\Support\Facades\Artisan;
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
    commitGitWorkspace($this->workspace);
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
    ['envelope' => $handoff, 'path' => $path] = app(HandOffTask::class)->handle($this->task->id, true, $this->sender, $this->recipient, 'implement', 'Implement the locked greeting test.');

    expect($handoff)->toBeInstanceOf(HandoffEnvelope::class)
        ->and(HandoffEnvelope::fromJson(File::get($path))->toArray())->toBe($handoff->toArray())
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
    app(HandOffTask::class)->handle($this->task->id, true, $this->sender, $this->recipient, 'implement', 'Implement the locked greeting test.', ['handoff_id' => $id]);
    app(HandOffTask::class)->handle($this->task->id, true, $this->sender, $this->recipient, 'implement', 'Implement the locked greeting test.', ['handoff_id' => $id]);

    $types = array_map(fn ($event) => $event->type()?->value, app(RecordLifecycleEvent::class)->load($this->workspace)->events($this->task->id));
    expect($types)->toBe(['created', 'handed_off']);
});

it('rejects a handoff that would merge or edit the protected test', function () {
    expect(fn () => app(HandOffTask::class)->handle($this->task->id, true, $this->sender, $this->recipient, 'merge', 'Merge the change.'))
        ->toThrow(RuntimeException::class, 'HANDOFF_PRIVILEGE');

    $writable = app(CreateTask::class)->handle('Author the test.', $this->workspace, [], 'tests/GreetingTest.php', allowTestEdits: true);
    $writable->runs()->create(['prompt' => $writable->prompt, 'workspace' => $writable->workspace, 'status' => 'completed', 'report' => []]);
    expect(fn () => app(HandOffTask::class)->handle($writable->id, true, $this->sender, $this->recipient, 'implement', 'Change the test.'))
        ->toThrow(RuntimeException::class, 'HANDOFF_TEST_WRITABLE');
});

it('saves the envelope with owner-only permissions and prints its path', function () {
    $root = realpath($this->workspace);

    expect(Artisan::call('molly:handoff', ['task' => $this->task->id, '--from' => $this->sender, '--to' => $this->recipient, '--approve' => true, '--json' => true]))->toBe(0);
    $printed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $path = $root.'/.molly/handoffs/'.$printed['handoff_id'].'.json';

    expect($printed)->toBe(HandoffEnvelope::fromArray($printed)->toArray())
        ->and(HandoffEnvelope::fromJson(File::get($path))->toArray())->toBe($printed)
        ->and(fileperms($root.'/.molly') & 0777)->toBe(0700)
        ->and(fileperms($root.'/.molly/handoffs') & 0777)->toBe(0700)
        ->and(fileperms($path) & 0777)->toBe(0600);

    $this->artisan('molly:handoff', ['task' => $this->task->id, '--from' => $this->sender, '--to' => $this->recipient, '--approve' => true])
        ->expectsOutputToContain('Saved handoff envelope: '.$root.'/.molly/handoffs/')
        ->assertExitCode(0);
    expect(File::files($root.'/.molly/handoffs'))->toHaveCount(2);

    expect(Artisan::call('molly:journal', ['task' => $this->task->id, '--json' => true]))->toBe(0);
    $journal = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['path'];
    expect($journal)->toBe($root.'/.molly/journal/'.$this->task->id.'.md')
        ->and(fileperms(dirname($journal)) & 0777)->toBe(0700)
        ->and(fileperms($journal) & 0777)->toBe(0600);
});

it('records no handoff when the envelope cannot be saved', function () {
    File::ensureDirectoryExists($this->workspace.'/.molly');
    File::put($this->workspace.'/.molly/handoffs', 'not a directory');

    expect(fn () => app(HandOffTask::class)->handle($this->task->id, true, $this->sender, $this->recipient, 'implement', 'Implement the locked greeting test.'))
        ->toThrow(RuntimeException::class, 'JOURNAL_PATH_INVALID');

    $types = array_map(fn ($event) => $event->type()?->value, app(RecordLifecycleEvent::class)->load($this->workspace)->events($this->task->id));
    expect($types)->not->toContain('handed_off');
});

it('refuses a handoff without approval before it checks the task', function () {
    $writable = app(CreateTask::class)->handle('Author the test.', $this->workspace, [], 'tests/GreetingTest.php', allowTestEdits: true);

    expect(fn () => app(HandOffTask::class)->handle($writable->id, false, $this->sender, $this->recipient, 'implement', 'Implement the locked greeting test.'))
        ->toThrow(RuntimeException::class, 'HANDOFF_UNCONFIRMED');

    $types = array_map(fn ($event) => $event->type()?->value, app(RecordLifecycleEvent::class)->load($this->workspace)->events($writable->id));
    expect($types)->not->toContain('handed_off');
});

it('rejects a recipient that widens file scope', function () {
    ['envelope' => $handoff] = app(HandOffTask::class)->handle($this->task->id, true, $this->sender, $this->recipient, 'implement', 'Implement the locked greeting test.');
    $this->task->update(['paths' => ['app/Greeting.php', 'app/Secret.php']]);

    expect(fn () => app(AcceptHandoff::class)->handle($handoff, $this->task->id, $this->recipient))
        ->toThrow(RuntimeException::class, 'HANDOFF_SCOPE_WIDENED');
});
