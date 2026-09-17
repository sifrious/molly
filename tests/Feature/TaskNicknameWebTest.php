<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;

beforeEach(function () {
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    config()->set('session.driver', 'array');
    config()->set('molly.ui.enabled', true);
    $this->workspace = sys_get_temp_dir().'/molly-nickname-web-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/tests');
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('saves a nickname and includes the required test without repeating the path in the web form', function () {
    Queue::fake();

    $response = $this->post('/molly/tasks', [
        'prompt' => 'Add a greeting endpoint.',
        'nickname' => ' Greeting-Endpoint ',
        'workspace' => $this->workspace,
        'paths' => "routes/web.php\n",
        'test_path' => 'tests/GreetingTest.php',
    ]);

    $task = Task::sole();
    $response->assertRedirect(route('molly.tasks.show', $task->id))->assertSessionHasNoErrors();
    expect($task->nickname)->toBe('greeting-endpoint')
        ->and($task->paths)->toBe(['routes/web.php', 'tests/GreetingTest.php'])
        ->and($task->status)->toBe('pending');
    $this->assertDatabaseCount('molly_runs', 0);
    Queue::assertNothingPushed();
});

it('imports a named test-only task without requiring other editable files', function () {
    Queue::fake();
    Process::fake(["'gh' 'api' '--hostname' 'github.com' 'repos/sifrious/molly/issues/1'" => Process::result(output: json_encode([
        'html_url' => 'https://github.com/sifrious/molly/issues/1',
        'number' => 1,
        'title' => 'Cover the greeting',
        'body' => 'Add a test for the greeting endpoint.',
        'updated_at' => '2026-09-17T12:00:00Z',
        'labels' => [],
    ]))]);

    $response = $this->post('/molly/tasks', [
        'issue_url' => 'https://github.com/sifrious/molly/issues/1',
        'nickname' => 'issue-one',
        'workspace' => $this->workspace,
        'paths' => '',
        'test_path' => 'tests/GreetingTest.php',
    ]);

    $task = Task::sole();
    $response->assertRedirect(route('molly.tasks.show', $task->id))->assertSessionHasNoErrors();
    expect($task->nickname)->toBe('issue-one')
        ->and($task->paths)->toBe(['tests/GreetingTest.php'])
        ->and($task->source['issue_number'])->toBe(1)
        ->and($task->status)->toBe('pending');
    $this->assertDatabaseCount('molly_runs', 0);
    Queue::assertNothingPushed();
});

it('keeps nicknames optional when saving a test-only task', function () {
    $response = $this->post('/molly/tasks', [
        'prompt' => 'Test the greeting.',
        'workspace' => $this->workspace,
        'test_path' => 'tests/GreetingTest.php',
    ]);

    $task = Task::sole();
    $response->assertRedirect(route('molly.tasks.show', $task->id))->assertSessionHasNoErrors();
    expect($task->nickname)->toBeNull()->and($task->paths)->toBe(['tests/GreetingTest.php']);
});

it('shows nickname validation feedback without saving a task', function () {
    Queue::fake();

    $response = $this->from('/molly/tasks/create')->post('/molly/tasks', [
        'prompt' => 'Test the greeting.',
        'nickname' => 'not a nickname',
        'workspace' => $this->workspace,
        'test_path' => 'tests/GreetingTest.php',
    ]);

    $response->assertRedirect('/molly/tasks/create')->assertSessionHasErrors('task');
    $this->get('/molly/tasks/create')->assertSee('TASK_NAME_INVALID');
    $this->assertDatabaseCount('molly_tasks', 0);
    $this->assertDatabaseCount('molly_runs', 0);
    Queue::assertNothingPushed();
});

it('renames an existing task without changing its lifecycle or run history', function () {
    $task = app(CreateTask::class)->handle('Test the greeting.', $this->workspace, [], 'tests/GreetingTest.php', nickname: 'old-name');
    $task->update(['status' => 'failed']);
    $run = Run::create(['task_id' => $task->id, 'prompt' => $task->prompt, 'workspace' => $this->workspace, 'status' => 'failed', 'report' => ['error' => 'A required test failed.']]);
    $taskBefore = Arr::except($task->fresh()->getAttributes(), ['nickname', 'updated_at', 'journal_status']);
    $runBefore = $run->fresh()->getAttributes();
    Queue::fake();

    $response = $this->post('/molly/tasks/'.$task->id.'/name', [
        'nickname' => ' Greeting-Tests ',
        'status' => 'completed',
        'prompt' => 'Do not replace the prompt.',
        'paths' => ['app/Other.php'],
    ]);

    $response->assertRedirect(route('molly.tasks.show', $task->id))->assertSessionHasNoErrors();
    expect($task->fresh()->nickname)->toBe('greeting-tests')
        ->and(Arr::except($task->fresh()->getAttributes(), ['nickname', 'updated_at', 'journal_status']))->toBe($taskBefore)
        ->and($run->fresh()->getAttributes())->toBe($runBefore);
    $this->assertDatabaseCount('molly_runs', 1);
    Queue::assertNothingPushed();
});

it('preserves both tasks when a requested nickname is already taken', function () {
    $task = app(CreateTask::class)->handle('First task.', $this->workspace, [], 'tests/FirstTest.php', nickname: 'first-task');
    $other = app(CreateTask::class)->handle('Second task.', $this->workspace, [], 'tests/SecondTest.php', nickname: 'second-task');
    $taskBefore = $task->fresh()->getAttributes();
    $otherBefore = $other->fresh()->getAttributes();
    Queue::fake();

    $response = $this->from('/molly/tasks/'.$task->id)->post('/molly/tasks/'.$task->id.'/name', ['nickname' => 'second-task']);

    $response->assertRedirect('/molly/tasks/'.$task->id)->assertSessionHasErrors('nickname')->assertSessionHasInput('nickname', 'second-task');
    $this->get('/molly/tasks/'.$task->id)->assertSee('TASK_NAME_TAKEN');
    expect($task->fresh()->getAttributes())->toBe($taskBefore)
        ->and($other->fresh()->getAttributes())->toBe($otherBefore);
    $this->assertDatabaseCount('molly_runs', 0);
    Queue::assertNothingPushed();
});

it('rejects an invalid nickname without changing the existing task', function () {
    $task = app(CreateTask::class)->handle('Test the greeting.', $this->workspace, [], 'tests/GreetingTest.php', nickname: 'greeting-tests');
    $before = $task->fresh()->getAttributes();

    $response = $this->post('/molly/tasks/'.$task->id.'/name', ['nickname' => 'bad name']);

    $response->assertSessionHasErrors('nickname');
    $this->get('/molly/tasks/'.$task->id)->assertSee('TASK_NAME_INVALID');
    expect($task->fresh()->getAttributes())->toBe($before);
});

it('shows the nickname and task ID while keeping task links and forms on UUID URLs', function () {
    $task = app(CreateTask::class)->handle('Test the greeting.', $this->workspace, [], 'tests/GreetingTest.php', nickname: 'greeting-tests');

    $this->get('/molly')->assertSee('greeting-tests')->assertSee($task->id)
        ->assertSee('href="'.route('molly.tasks.show', $task->id).'"', false);
    $this->get('/molly/tasks/'.$task->id)->assertSee('<h1>greeting-tests</h1>', false)->assertSee($task->id)
        ->assertSee('action="'.route('molly.tasks.name', $task->id).'"', false)
        ->assertSee('name="_token"', false)->assertSee('for="nickname"', false)
        ->assertSee('Save nickname');
});

it('does not rename a task for a remote web client', function () {
    $task = app(CreateTask::class)->handle('Test the greeting.', $this->workspace, [], 'tests/GreetingTest.php', nickname: 'greeting-tests');
    $before = $task->fresh()->getAttributes();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->post('/molly/tasks/'.$task->id.'/name', ['nickname' => 'changed-name'])->assertForbidden();

    expect($task->fresh()->getAttributes())->toBe($before);
});

it('requires a CSRF token before renaming a task outside the test bypass', function () {
    $task = app(CreateTask::class)->handle('Test the greeting.', $this->workspace, [], 'tests/GreetingTest.php', nickname: 'greeting-tests');
    $before = $task->fresh()->getAttributes();
    $this->app->detectEnvironment(fn () => 'local');

    try {
        $this->post('/molly/tasks/'.$task->id.'/name', ['nickname' => 'changed-name'])->assertStatus(419);
        expect($task->fresh()->getAttributes())->toBe($before);
    } finally {
        $this->app->detectEnvironment(fn () => 'testing');
    }
});

it('returns not found when renaming an unknown task', function () {
    $this->post('/molly/tasks/'.Str::uuid().'/name', ['nickname' => 'missing-task'])->assertNotFound();

    $this->assertDatabaseCount('molly_tasks', 0);
});
