<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\NameTask;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Actions\ShowTask;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;

function nicknameTask(array $attributes = []): Task
{
    return Task::create([
        'prompt' => 'Add a readiness check.',
        'workspace' => sys_get_temp_dir(),
        'paths' => ['routes/web.php', 'tests/Feature/ReadyTest.php'],
        'test_path' => 'tests/Feature/ReadyTest.php',
        ...$attributes,
    ]);
}

it('keeps unnamed tasks addressable by UUID', function () {
    $first = nicknameTask();
    $second = nicknameTask();

    expect(Task::findByReference(strtoupper($first->id))?->id)->toBe($first->id)
        ->and($first->reference())->toBe($first->id)
        ->and($second->fresh()->nickname)->toBeNull()
        ->and(Task::findByReference('unknown-task'))->toBeNull()
        ->and(Task::findByReference(''))->toBeNull();
});

it('adds nicknames to existing tasks without changing saved attempts', function () {
    $migration = require __DIR__.'/../../database/migrations/2026_09_17_051234_add_nickname_to_molly_tasks_table.php';
    $migration->down();
    $task = nicknameTask();
    $run = $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => ['error' => 'Previous failure.']]);

    $migration->up();
    $named = app(NameTask::class)->handle($task->id, 'ready-check');

    expect($named->id)->toBe($task->id)
        ->and($named->nickname)->toBe('ready-check')
        ->and($named->runs->modelKeys())->toBe([$run->id])
        ->and($run->fresh()->report)->toBe(['error' => 'Previous failure.']);
});

it('renames a task without changing its identity or attempt history', function () {
    $task = nicknameTask(['nickname' => 'old-name', 'status' => 'failed', 'source' => ['issue_number' => 42]]);
    $run = $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => ['error' => 'A test failed.']]);
    $before = $task->fresh()->getRawOriginal();
    unset($before['nickname'], $before['updated_at']);

    $named = app(NameTask::class)->handle(' OLD-NAME ', ' Ready-Check ');
    $found = app(ShowTask::class)->handle('READY-CHECK');

    expect($named->nickname)->toBe('ready-check')
        ->and($named->reference())->toBe('ready-check')
        ->and(array_intersect_key($named->getRawOriginal(), $before))->toBe($before)
        ->and($found->id)->toBe($task->id)
        ->and($found->runs->modelKeys())->toBe([$run->id])
        ->and($found->runs->first()->report)->toBe(['error' => 'A test failed.'])
        ->and(Task::findByReference('old-name'))->toBeNull()
        ->and(Task::findByReference($task->id)?->id)->toBe($task->id);
});

it('accepts the nickname length boundaries and an unchanged nickname', function (string $nickname) {
    $task = nicknameTask();

    app(NameTask::class)->handle($task->id, $nickname);
    $named = app(NameTask::class)->handle($nickname, strtoupper($nickname));

    expect($named->nickname)->toBe($nickname)->and(Task::count())->toBe(1);
})->with(['one character' => 'a', 'sixty-four characters' => str_repeat('a', 64)]);

it('rejects invalid nicknames without changing the task', function (string $nickname) {
    $task = nicknameTask(['nickname' => 'existing']);

    expect(fn () => app(NameTask::class)->handle($task->id, $nickname))
        ->toThrow(RuntimeException::class, 'TASK_NAME_INVALID');

    expect($task->fresh()->nickname)->toBe('existing')->and($task->runs()->count())->toBe(0);
})->with([
    'empty' => '',
    'blank' => ' ',
    'too long' => str_repeat('a', 65),
    'starts with a number' => '1-ready',
    'starts with a hyphen' => '-ready',
    'space inside name' => 'ready check',
    'underscore' => 'ready_check',
    'slash' => 'ready/check',
    'unicode' => 'rêady',
    'line break' => "ready\ncheck",
    'UUID-shaped' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
    'markup' => '<script>',
]);

it('rejects a duplicate nickname across workspaces', function () {
    $owner = nicknameTask(['nickname' => 'ready-check', 'workspace' => '/first-checkout']);
    $task = nicknameTask(['workspace' => '/second-checkout']);

    expect(fn () => app(NameTask::class)->handle($task->id, 'READY-CHECK'))
        ->toThrow(RuntimeException::class, 'TASK_NAME_TAKEN');

    expect($task->fresh()->nickname)->toBeNull()
        ->and(Task::findByReference('ready-check')?->id)->toBe($owner->id);
});

it('enforces nickname uniqueness when a writer bypasses validation', function () {
    nicknameTask(['nickname' => 'ready-check']);

    expect(fn () => nicknameTask(['nickname' => 'ready-check']))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Task::count())->toBe(1);
});

it('reports a name collision claimed after validation without renaming the losing task', function () {
    $task = nicknameTask(['nickname' => 'before']);
    $other = nicknameTask();
    $event = 'eloquent.updating: '.Task::class;
    Event::listen($event, function (Task $updating) use ($task, $other): void {
        if ($updating->id === $task->id) {
            DB::table('molly_tasks')->where('id', $other->id)->update(['nickname' => 'ready-check']);
        }
    });

    try {
        expect(fn () => app(NameTask::class)->handle($task->id, 'ready-check'))
            ->toThrow(RuntimeException::class, 'TASK_NAME_TAKEN');
    } finally {
        Event::forget($event);
    }

    expect($task->fresh()->nickname)->toBe('before')
        ->and($other->fresh()->nickname)->toBe('ready-check');
});

it('returns the canonical ID and nickname when naming or inspecting through the CLI', function () {
    $task = nicknameTask();

    $exit = Artisan::call('molly:name', ['task' => $task->id, 'name' => 'Ready-Check', '--json' => true]);
    $named = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $readExit = Artisan::call('molly:task', ['task' => 'ready-check', '--json' => true]);
    $read = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)->and($readExit)->toBe(0)
        ->and($named['id'])->toBe($task->id)
        ->and($named['task']['nickname'])->toBe('ready-check')
        ->and($read['id'])->toBe($task->id)
        ->and($read['task']['runs'])->toBe([]);
});

it('asks for a missing name in an interactive command', function () {
    $task = nicknameTask();

    $this->artisan('molly:name', ['task' => $task->id])
        ->expectsQuestion('What should this task be called?', 'Ready-Check')
        ->expectsOutputToContain('php artisan molly:task ready-check')
        ->assertSuccessful();

    expect($task->fresh()->nickname)->toBe('ready-check');
});

it('requires an explicit name in noninteractive commands', function (array $options) {
    $task = nicknameTask();

    $this->artisan('molly:name', ['task' => $task->id, ...$options])
        ->expectsOutputToContain('Provide a task name when using --json or --no-interaction.')
        ->assertFailed();

    expect($task->fresh()->nickname)->toBeNull();
})->with(['JSON' => [['--json' => true]], 'no interaction' => [['--no-interaction' => true]]]);

it('returns a naming error without creating an unknown task', function () {
    $exit = Artisan::call('molly:name', ['task' => 'missing-task', 'name' => 'ready-check', '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($result)->toBe(['id' => 'missing-task', 'status' => 'error', 'error' => 'TASK_NOT_FOUND: No saved task has that name or ID.'])
        ->and(Task::count())->toBe(0);
});

it('shows a task name alongside its UUID and suggests the named start command', function () {
    $task = nicknameTask(['nickname' => 'ready-check']);

    $this->artisan('molly:task', ['task' => 'ready-check'])
        ->expectsOutputToContain('Task ready-check / pending')
        ->expectsOutputToContain('Task ID: '.$task->id)
        ->expectsOutputToContain('php artisan molly:start ready-check')
        ->assertSuccessful();
    $this->withoutMockingConsoleOutput();
    $exit = Artisan::call('molly:tasks');

    expect($exit)->toBe(0)->and(Artisan::output())->toContain('ready-check', $task->id);
});

it('starts and retries by nickname while retaining UUID locks and run relationships', function (string $command, string $status) {
    $workspace = sys_get_temp_dir().'/molly-nickname-'.Str::uuid();
    File::ensureDirectoryExists($workspace);
    $task = nicknameTask(['nickname' => 'ready-check', 'workspace' => $workspace, 'status' => $status]);
    $this->mock(RunTask::class)->shouldReceive('handle')->once()->andReturnUsing(function (string $prompt, string $root, array $paths, string $testPath, ?Closure $progress, string $taskId, Closure $shouldStop) use ($task): Run {
        expect($taskId)->toBe($task->id);
        expect(fn () => (new Workspace($root))->exclusivelyForTask($task->id, fn (): bool => true))
            ->toThrow(RuntimeException::class, 'WORKSPACE_BUSY');

        return Run::create(['task_id' => $taskId, 'prompt' => $prompt, 'workspace' => $root, 'status' => 'completed', 'report' => []]);
    });

    try {
        $exit = Artisan::call($command, ['task' => 'READY-CHECK', '--json' => true]);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0)
            ->and($result['task_id'])->toBe($task->id)
            ->and($task->fresh()->status)->toBe('completed')
            ->and($task->runs()->first()->task_id)->toBe($task->id);
    } finally {
        File::deleteDirectory($workspace);
    }
})->with(['start' => ['molly:start', 'pending'], 'retry' => ['molly:retry', 'failed']]);

it('stops a saved task by nickname and returns its UUID', function () {
    $task = nicknameTask(['nickname' => 'ready-check']);

    $exit = Artisan::call('molly:stop', ['task' => 'READY-CHECK', '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)->and($result['id'])->toBe($task->id)
        ->and($task->fresh()->status)->toBe('stopped')
        ->and($task->runs()->count())->toBe(0);
});
