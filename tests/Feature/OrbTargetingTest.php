<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\TarpitReviewer;
use Sifrious\Molly\Contracts\ExecutionTargetKind;
use Sifrious\Molly\Contracts\ExecutionTargetRequest;
use Sifrious\Molly\Execution\SelectExecutionTarget;
use Sifrious\Molly\Jobs\StartSavedTask;
use Sifrious\Molly\Mcp\MollyServer;
use Sifrious\Molly\Mcp\MollyTask;
use Sifrious\Molly\Models\Orb;
use Sifrious\Molly\Models\Task;
use Symfony\Component\Process\Process;

/*
 * Registering, listing, checking, revoking, and placing local Orbs, in one process. Each test
 * builds a committed Laravel-shaped repository, an approved worktree root beside it, and one
 * linked worktree per task. OrbExecutionTest runs the same rules with real queue workers.
 */

/**
 * Make the fake Ollama list these models from now on, as a running Ollama would, or refuse
 * every connection when $models is null.
 *
 * @param  list<string>|null  $models
 */
function fakeOrbOllama(?array $models = ['gpt-oss:120b-code', 'gpt-oss:20b']): void
{
    test()->ollamaModels = $models;
}

/** Add a linked worktree of the test repository under the approved root and return its canonical path. */
function orbTargetingWorktree(string $name, ?string $repository = null): string
{
    $repository ??= test()->repository;
    (new Process(['git', '-C', $repository, 'worktree', 'add', '--quiet', '-b', 'orb-'.$name.'-'.bin2hex(random_bytes(3)), test()->orbs.'/'.$name]))->mustRun();

    return realpath(test()->orbs.'/'.$name);
}

/** Save the documented ready task in a workspace and return it. */
function orbTargetingTask(string $workspace, string $name, string $prompt = 'Add GET /ready returning exactly {"ready":true}. Preserve existing routes.'): Task
{
    [$exit, $saved] = mollyJson('molly:create', [
        'prompt' => $prompt,
        '--workspace' => $workspace,
        '--name' => $name,
        '--test' => 'tests/Feature/ReadyTest.php',
        '--file' => ['routes/web.php'],
    ]);
    expect($exit)->toBe(0, json_encode($saved));

    return Task::findOrFail($saved['id']);
}

/** @return array<string, mixed> the registered Orb as molly:orb-register --json reports it */
function registerOrb(string $name, string $model, array $options = []): array
{
    [$exit, $result] = mollyJson('molly:orb-register', ['name' => $name, '--model' => $model, '--repository' => test()->repository, '--worktree-root' => test()->orbs, ...$options]);
    expect($exit)->toBe(0, json_encode($result));

    return $result['orb'];
}

function placeOnOrb(Task $task, ?string $orb, ?string $model = null, ?string $runtime = null)
{
    return app(SelectExecutionTarget::class)->handle(ExecutionTargetRequest::orb($orb, $runtime, $model), $task->fresh());
}

beforeEach(function (): void {
    config(['ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434']]);
    fakeOrbOllama();
    Http::fake(function (Request $request) {
        if (! str_starts_with($request->url(), 'http://127.0.0.1:11434/api/')) {
            return null;
        }
        if ($this->ollamaModels === null) {
            throw new ConnectionException('Connection refused');
        }

        return str_ends_with($request->url(), '/api/version')
            ? Http::response(['version' => '0.12.3'])
            : Http::response(['models' => array_map(fn (string $model): array => ['name' => $model, 'model' => $model, 'digest' => hash('sha256', $model)], $this->ollamaModels)]);
    });
    $repository = laravelShapedWorkspace();
    File::copy(dirname(__DIR__).'/Fixtures/docs/ReadyTest.example.php', $repository.'/tests/Feature/ReadyTest.php');
    commitGitWorkspace($repository, 'Add the ready test');
    File::ensureDirectoryExists($repository.'-orbs');
    $this->repository = realpath($repository);
    $this->orbs = realpath($repository.'-orbs');
    $this->cleanup = [$repository, $repository.'-orbs'];
});

afterEach(function (): void {
    foreach ($this->cleanup as $path) {
        is_link($path) ? unlink($path) : File::deleteDirectory($path);
    }
});

it('registers two named Orbs that list as healthy with capabilities, repository authority, and no task', function (): void {
    $big = registerOrb('big', 'gpt-oss:120b-code');
    $small = registerOrb('small', 'gpt-oss:20b');
    [$exit, $listed] = mollyJson('molly:orbs', []);
    $orbs = array_column($listed['orbs'], null, 'name');

    expect($exit)->toBe(0)
        ->and(array_keys($orbs))->toBe(['big', 'small'])
        ->and(Str::isUuid($big['id']))->toBeTrue()
        ->and($big['id'])->not->toBe($small['id'])
        ->and($orbs['big'])->toMatchArray([
            'id' => $big['id'],
            'provider' => 'local',
            'device' => gethostname(),
            'availability' => 'available',
            'health' => 'healthy',
            'health_reason' => null,
            'runtime' => 'ollama',
            'model' => 'gpt-oss:120b-code',
            'worktree_root' => $this->orbs,
            'queue' => 'molly-orb-'.$big['id'],
            'current_task' => null,
            'revoked_at' => null,
        ])
        ->and($orbs['big']['capabilities'])->toContain('git_worktree', 'runtime_ollama', 'workspace_mapping')
        ->and($orbs['big']['repository'])->toMatchArray(['path' => $this->repository, 'git_common_dir' => realpath($this->repository.'/.git')])
        ->and($orbs['big']['runtime_identity'])->toMatchArray(['runtime' => 'ollama', 'version' => '0.12.3', 'model' => 'gpt-oss:120b-code', 'digest' => hash('sha256', 'gpt-oss:120b-code')])
        ->and($orbs['big']['heartbeat_at'])->not->toBeNull()
        ->and($orbs['big']['worker'])->toBe(['state' => 'stopped', 'pid' => null])
        ->and($orbs['small'])->toMatchArray(['availability' => 'available', 'health' => 'healthy', 'model' => 'gpt-oss:20b']);

    Artisan::call('molly:orbs');
    expect(Artisan::output())->toContain('big', 'small', 'available', 'ollama / gpt-oss:20b', $big['id']);
});

it('refuses an Orb it cannot register and saves nothing', function (array $options, string $code): void {
    registerOrb('taken', 'gpt-oss:20b');
    $arguments = ['name' => 'big', '--model' => 'gpt-oss:20b', '--repository' => $this->repository, '--worktree-root' => $this->orbs];
    foreach ($options as $key => $value) {
        $value === null ? $arguments = array_diff_key($arguments, [$key => true]) : $arguments[$key] = str_replace(['REPOSITORY', 'ORBS'], [$this->repository, $this->orbs], $value);
    }
    [$exit, $result] = mollyJson('molly:orb-register', $arguments);

    expect($exit)->toBe(1)
        ->and($result['error'])->toStartWith($code.':')
        ->and(Orb::pluck('name')->all())->toBe(['taken']);
})->with([
    'a name with spaces' => [['name' => 'Big Orb'], 'ORB_NAME_INVALID'],
    'a UUID as the name' => [['name' => '11111111-1111-4111-8111-111111111111'], 'ORB_NAME_INVALID'],
    'a name in use' => [['name' => 'taken'], 'ORB_NAME_TAKEN'],
    'an unknown runtime' => [['--runtime' => 'codex'], 'ORB_RUNTIME_INVALID'],
    'a cloud model' => [['--model' => 'gpt-oss:120b-cloud'], 'ORB_MODEL_INVALID'],
    'an Ollama Orb without a model' => [['--model' => null], 'ORB_MODEL_INVALID'],
    'an Amp Orb with a model' => [['--runtime' => 'amp'], 'ORB_MODEL_INVALID'],
    'no worktree root' => [['--worktree-root' => null], 'ORB_WORKTREE_ROOT_REQUIRED'],
    'a missing worktree root' => [['--worktree-root' => 'ORBS/missing'], 'ORB_WORKTREE_ROOT_INVALID'],
    'a worktree root inside .git' => [['--worktree-root' => 'REPOSITORY/.git'], 'ORB_WORKTREE_ROOT_INVALID'],
    'a repository that is not Git' => [['--repository' => 'ORBS'], 'ORB_REPOSITORY_INVALID'],
]);

it('records an unhealthy Orb, refuses to place a task on it, and rechecks health with molly:orbs --check', function (): void {
    fakeOrbOllama(['gpt-oss:20b']);
    $big = registerOrb('big', 'gpt-oss:120b-code');
    $task = orbTargetingTask(orbTargetingWorktree('ready'), 'ready');

    expect($big)->toMatchArray(['health' => 'unhealthy', 'availability' => 'unhealthy'])
        ->and($big['health_reason'])->toStartWith('ORB_MODEL_MISSING: Ollama at http://127.0.0.1:11434 has no model named gpt-oss:120b-code.')
        ->and(fn () => placeOnOrb($task, 'big'))->toThrow(RuntimeException::class, 'ORB_UNHEALTHY: Orb big failed its health check. ORB_MODEL_MISSING');

    $this->travel(5)->minutes();
    fakeOrbOllama(['gpt-oss:120b-code']);
    [, $stale] = mollyJson('molly:orbs', []);
    [, $checked] = mollyJson('molly:orbs', ['--check' => true]);

    expect($stale['orbs'][0]['health'])->toBe('unhealthy')
        ->and($checked['orbs'][0])->toMatchArray(['health' => 'healthy', 'availability' => 'available', 'health_reason' => null])
        ->and(strtotime($checked['orbs'][0]['heartbeat_at']))->toBeGreaterThan(strtotime($stale['orbs'][0]['heartbeat_at']));
});

it('reports an unreachable Ollama as unhealthy', function (): void {
    fakeOrbOllama(null);
    $big = registerOrb('big', 'gpt-oss:20b');

    expect($big['health'])->toBe('unhealthy')
        ->and($big['health_reason'])->toStartWith('ORB_RUNTIME_UNREACHABLE: Molly could not connect to Ollama at http://127.0.0.1:11434.');
});

it('places a task on an Orb named by name or by ID and reserves the Orb for it', function (string $by): void {
    $big = registerOrb('big', 'gpt-oss:120b-code');
    $task = orbTargetingTask(orbTargetingWorktree('ready'), 'ready');

    $snapshot = placeOnOrb($task, $by === 'name' ? 'big' : strtoupper($big['id']));
    $orb = Orb::findOrFail($big['id']);

    expect($snapshot->kind)->toBe(ExecutionTargetKind::Orb)
        ->and($snapshot->targetId)->toBe($big['id'])
        ->and($snapshot->provider)->toBe('local_orb')
        ->and($snapshot->capabilities)->toContain('git_worktree', 'runtime_ollama')
        ->and($snapshot->selectionReason)->toBe('The request named Orb big.')
        ->and($orb->current_task_id)->toBe($task->id)
        ->and($orb->reserved_prompt_sha256)->toBe(hash('sha256', $task->prompt))
        ->and($orb->availability())->toBe('busy');

    // Placing the same task again keeps its reservation.
    placeOnOrb($task, 'big');
    expect(Orb::findOrFail($big['id'])->current_task_id)->toBe($task->id);
})->with(['name', 'id']);

it('chooses the first idle healthy Orb by name that has the required runtime and model', function (): void {
    registerOrb('charlie', 'gpt-oss:20b');
    registerOrb('alpha', 'gpt-oss:20b');
    registerOrb('bravo', 'gpt-oss:120b-code');
    $first = orbTargetingTask(orbTargetingWorktree('first'), 'first');
    $second = orbTargetingTask(orbTargetingWorktree('second'), 'second');
    $third = orbTargetingTask(orbTargetingWorktree('third'), 'third');
    $big = orbTargetingTask(orbTargetingWorktree('big'), 'big-task');

    $chosen = fn (Task $task, ?string $model, ?string $runtime = null): string => Orb::findOrFail(placeOnOrb($task, null, $model, $runtime)->targetId)->name;

    expect($chosen($first, 'gpt-oss:20b'))->toBe('alpha')
        ->and($chosen($second, 'gpt-oss:20b'))->toBe('charlie')
        ->and($chosen($big, 'gpt-oss:120b-code', 'ollama'))->toBe('bravo')
        ->and(fn () => placeOnOrb($third, null, 'gpt-oss:20b'))->toThrow(RuntimeException::class, 'ORB_UNAVAILABLE: No registered Orb that runs any runtime gpt-oss:20b can take task third. alpha: ORB_BUSY: Orb alpha is reserved for task first, which is queued on it.')
        ->and(fn () => placeOnOrb($third, null, 'llama3', 'ollama'))->toThrow(RuntimeException::class, 'ORB_UNAVAILABLE: No registered Orb that runs ollama llama3 can take task third. Register one with php artisan molly:orb-register.')
        ->and(fn () => placeOnOrb($third, 'bravo', 'gpt-oss:20b'))->toThrow(RuntimeException::class, 'ORB_BUSY');

    mollyJson('molly:stop', ['task' => 'first']);
    expect(placeOnOrb($third, null, null, 'ollama')->selectionReason)->toBe('Orb alpha is the first idle Orb by name that runs ollama.');
    mollyJson('molly:stop', ['task' => 'big-task']);
    expect(fn () => placeOnOrb($first->fresh(), 'bravo', 'gpt-oss:20b'))->toThrow(RuntimeException::class, 'ORB_CAPABILITY_MISMATCH: Orb bravo runs ollama gpt-oss:120b-code, not any runtime gpt-oss:20b.');
});

it('refuses a busy Orb, a task placed on another Orb, and an occupied worktree', function (): void {
    registerOrb('big', 'gpt-oss:120b-code');
    registerOrb('small', 'gpt-oss:20b');
    registerOrb('spare', 'gpt-oss:20b');
    $shared = orbTargetingWorktree('shared');
    $running = orbTargetingTask($shared, 'running');
    $sibling = orbTargetingTask($shared, 'sibling');
    $queued = orbTargetingTask(orbTargetingWorktree('queued'), 'queued');
    $neighbor = orbTargetingTask($queued->workspace, 'neighbor');

    placeOnOrb($queued, 'big');
    Task::whereKey($running->id)->update(['status' => 'running']);

    expect(fn () => placeOnOrb($sibling, 'big'))->toThrow(RuntimeException::class, 'ORB_BUSY: Orb big is reserved for task queued, which is queued on it. Each Orb takes one task at a time.')
        ->and(fn () => placeOnOrb($queued, 'small'))->toThrow(RuntimeException::class, 'ORB_TASK_PLACED: Task queued is already placed on Orb big.')
        ->and(fn () => placeOnOrb($sibling, 'small'))->toThrow(RuntimeException::class, 'ORB_WORKTREE_OCCUPIED: Task running is running in '.$shared.'. No two active runs write to the same worktree.')
        ->and(fn () => placeOnOrb($neighbor, 'spare'))->toThrow(RuntimeException::class, 'ORB_WORKTREE_OCCUPIED: Task queued is placed on an Orb in '.$queued->workspace.'.')
        ->and(Orb::where('name', 'small')->value('current_task_id'))->toBeNull()
        ->and(Orb::where('name', 'spare')->value('current_task_id'))->toBeNull();
});

it('refuses a task that is not in a canonical linked worktree of the Orb repository under its root', function (string $case, string $message): void {
    $root = $case === 'main checkout' ? dirname($this->repository) : $this->orbs;
    registerOrb('big', 'gpt-oss:20b', ['--worktree-root' => $root]);
    $workspace = match ($case) {
        'outside the root', 'main checkout' => $this->repository,
        'another repository' => (function (): string {
            $other = laravelShapedWorkspace();
            File::copy(dirname(__DIR__).'/Fixtures/docs/ReadyTest.example.php', $other.'/tests/Feature/ReadyTest.php');
            commitGitWorkspace($other, 'Add the ready test');
            $this->cleanup[] = $other;

            return orbTargetingWorktree('other', realpath($other));
        })(),
        default => orbTargetingWorktree('ready'),
    };
    $task = orbTargetingTask($workspace, 'ready');
    if ($case === 'a swapped link') {
        rename($workspace, $workspace.'-moved');
        symlink($workspace.'-moved', $workspace);
        $this->cleanup[] = $workspace.'-moved';
    }
    if ($case === 'a swapped root') {
        rename($this->orbs, $this->orbs.'-moved');
        symlink($this->orbs.'-moved', $this->orbs);
        $this->cleanup = [$this->orbs, $this->orbs.'-moved', ...$this->cleanup];
    }
    $message = str_replace(['{repository}', '{orbs}', '{workspace}'], [$this->repository, $this->orbs, $workspace], $message);

    expect(fn () => placeOnOrb($task, 'big'))->toThrow(RuntimeException::class, $message)
        ->and(Orb::where('name', 'big')->value('current_task_id'))->toBeNull();
})->with([
    ['outside the root', 'ORB_WORKTREE_OUTSIDE_ROOT: Task ready runs in {repository}, which is not under {orbs}, the worktree root approved for Orb big.'],
    ['main checkout', 'ORB_WORKTREE_NOT_LINKED: {repository} is the main checkout of {repository}, not a linked worktree.'],
    ['another repository', 'ORB_REPOSITORY_NOT_GRANTED: {workspace} is not a worktree of {repository}, the repository Orb big may change.'],
    ['a swapped link', 'ORB_WORKTREE_INVALID: The worktree {workspace} for task ready now resolves to {workspace}-moved.'],
    ['a swapped root', 'ORB_WORKTREE_ROOT_INVALID: The approved worktree root {orbs} for Orb big is missing or now resolves to {orbs}-moved.'],
]);

it('requires a saved task and a registered Orb', function (): void {
    $task = orbTargetingTask(orbTargetingWorktree('ready'), 'ready');

    expect(fn () => app(SelectExecutionTarget::class)->handle(new ExecutionTargetRequest(ExecutionTargetKind::Orb, 'big')))
        ->toThrow(RuntimeException::class, 'ORB_TASK_REQUIRED')
        ->and(fn () => placeOnOrb($task, 'amp-thread-T-123'))
        ->toThrow(RuntimeException::class, 'ORB_NOT_FOUND: No registered Orb has the name or ID amp-thread-T-123.');
});

it('revokes an Orb so it takes no task, a queued task loses its place, and a running task is asked to stop', function (): void {
    registerOrb('big', 'gpt-oss:120b-code');
    registerOrb('small', 'gpt-oss:20b');
    $queued = orbTargetingTask(orbTargetingWorktree('queued'), 'queued');
    $running = orbTargetingTask(orbTargetingWorktree('running'), 'running');
    placeOnOrb($queued, 'big');
    placeOnOrb($running, 'small');
    Task::whereKey($running->id)->update(['status' => 'running']);

    [$exit, $big] = mollyJson('molly:orb-revoke', ['orb' => 'big', '--reason' => 'Moving to a new Mac.']);
    [, $small] = mollyJson('molly:orb-revoke', ['orb' => 'small']);
    [, $again] = mollyJson('molly:orb-revoke', ['orb' => 'small']);
    [$workerExit, $worker] = mollyJson('molly:worker', ['action' => 'start', '--orb' => 'big', '--workspace' => $this->repository]);

    expect($exit)->toBe(0)
        ->and($big['orb'])->toMatchArray(['availability' => 'revoked', 'revoked_reason' => 'Moving to a new Mac.', 'current_task' => null])
        ->and($big['released_task'])->toBe('queued')
        ->and($queued->fresh()->status)->toBe('pending')
        ->and($small['stopping_task'])->toBe('running')
        ->and($running->fresh()->stop_requested_at)->not->toBeNull()
        ->and($again['already_revoked'])->toBeTrue()
        ->and(fn () => placeOnOrb($queued, 'big'))->toThrow(RuntimeException::class, 'ORB_REVOKED: Orb big was revoked at')
        ->and(fn () => placeOnOrb($queued, null, 'gpt-oss:20b'))->toThrow(RuntimeException::class, 'ORB_UNAVAILABLE: No registered Orb that runs any runtime gpt-oss:20b can take task queued. Register one')
        ->and($workerExit)->toBe(1)
        ->and($worker['error'])->toStartWith('ORB_REVOKED: Orb big was revoked')
        ->and(fn () => registerOrb('big', 'gpt-oss:20b'))->toThrow(Exception::class);
});

it('frees the Orb when a task queued on it is stopped', function (): void {
    registerOrb('big', 'gpt-oss:120b-code');
    $task = orbTargetingTask(orbTargetingWorktree('ready'), 'ready');
    placeOnOrb($task, 'big');

    mollyJson('molly:stop', ['task' => 'ready']);

    expect($task->fresh()->status)->toBe('stopped')
        ->and(Orb::where('name', 'big')->value('current_task_id'))->toBeNull();
});

it('keeps a queued reservation when a start names another Orb, and frees the named Orb when its queued start is refused', function (): void {
    registerOrb('big', 'gpt-oss:120b-code');
    registerOrb('small', 'gpt-oss:20b');
    $task = orbTargetingTask(orbTargetingWorktree('ready'), 'ready');
    placeOnOrb($task, 'big');
    ChangeWriter::fake()->preventStrayPrompts();

    [$exit, $elsewhere] = mollyJson('molly:start', ['task' => 'ready', '--orb' => 'small']);
    expect($exit)->toBe(1)
        ->and($elsewhere['report']['error'])->toStartWith('ORB_TASK_PLACED: Task ready is already placed on Orb big.')
        ->and(Orb::where('name', 'big')->value('current_task_id'))->toBe($task->id);

    // The start queued for big finds big unhealthy, so the task stays pending and big is free.
    fakeOrbOllama(['gpt-oss:20b']);
    $big = Orb::where('name', 'big')->firstOrFail();
    expect(fn () => app(StartTask::class)->handle($task->id, target: new ExecutionTargetRequest(ExecutionTargetKind::Orb, $big->id)))
        ->toThrow(RuntimeException::class, 'ORB_UNHEALTHY');
    expect($task->fresh()->status)->toBe('pending')
        ->and($big->fresh()->current_task_id)->toBeNull();
    ChangeWriter::assertNeverPrompted();
});

it('queues a start or retry on the Orb queue, by name or by required model, and refuses a busy Orb', function (): void {
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 3700]);
    Queue::fake([StartSavedTask::class]);
    $big = registerOrb('big', 'gpt-oss:120b-code');
    $small = registerOrb('small', 'gpt-oss:20b');
    $ready = orbTargetingTask(orbTargetingWorktree('ready'), 'ready');
    $second = orbTargetingTask(orbTargetingWorktree('second'), 'second');
    $third = orbTargetingTask(orbTargetingWorktree('third'), 'third');
    Task::whereKey($second->id)->update(['status' => 'failed']);

    [$exit, $queued] = mollyJson('molly:queue', ['task' => 'ready', '--orb' => 'big']);
    [$retryExit, $retried] = mollyJson('molly:queue', ['task' => 'second', '--retry' => true, '--orb-model' => 'gpt-oss:20b']);
    [$busyExit, $busy] = mollyJson('molly:queue', ['task' => 'third', '--orb' => 'big']);

    expect($exit)->toBe(0)
        ->and($queued)->toMatchArray(['status' => 'queued', 'task' => 'ready', 'retry' => false, 'queue' => 'molly-orb-'.$big['id'], 'orb' => ['id' => $big['id'], 'name' => 'big', 'runtime' => 'ollama', 'model' => 'gpt-oss:120b-code']])
        ->and($retryExit)->toBe(0)
        ->and($retried)->toMatchArray(['retry' => true, 'queue' => 'molly-orb-'.$small['id']])
        ->and($busyExit)->toBe(1)
        ->and($busy['error'])->toStartWith('ORB_BUSY: Orb big is reserved for task ready, which is queued on it.');
    Queue::assertPushedOn('molly-orb-'.$big['id'], StartSavedTask::class, fn (StartSavedTask $job): bool => $job->taskId === $ready->id && $job->orbId === $big['id'] && ! $job->retry);
    Queue::assertPushedOn('molly-orb-'.$small['id'], StartSavedTask::class, fn (StartSavedTask $job): bool => $job->taskId === $second->id && $job->orbId === $small['id'] && $job->retry);
    Queue::assertPushed(StartSavedTask::class, 2);
    expect(Task::findOrFail($third->id)->status)->toBe('pending');
});

it('queues an MCP start or retry on an Orb the same way', function (string $operation): void {
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 3700]);
    Queue::fake([StartSavedTask::class]);
    registerOrb('big', 'gpt-oss:120b-code');
    $small = registerOrb('small', 'gpt-oss:20b');
    $task = orbTargetingTask(orbTargetingWorktree('ready'), 'ready');
    if ($operation === 'retry') {
        Task::whereKey($task->id)->update(['status' => 'failed']);
    }

    MollyServer::tool(MollyTask::class, ['operation' => $operation, 'id' => 'ready', 'orb_model' => 'gpt-oss:20b'])->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('queued', true)->where('orb.name', 'small')->where('orb.queue', 'molly-orb-'.$small['id'])->etc());
    MollyServer::tool(MollyTask::class, ['operation' => $operation, 'id' => 'ready', 'orb' => 'big'])->assertHasErrors()->assertSee('ORB_TASK_PLACED');

    Queue::assertPushedOn('molly-orb-'.$small['id'], StartSavedTask::class, fn (StartSavedTask $job): bool => $job->taskId === $task->id && $job->orbId === $small['id'] && $job->retry === ($operation === 'retry'));
    Queue::assertPushed(StartSavedTask::class, 1);
})->with(['start', 'retry']);

it('runs a task on an Orb in the terminal and records the Orb in the report, receipts, and journal', function (): void {
    $big = registerOrb('big', 'gpt-oss:120b-code');
    $worktree = orbTargetingWorktree('ready');
    $task = orbTargetingTask($worktree, 'ready');
    $seen = [];
    ChangeWriter::fake(function () use (&$seen): array {
        $seen[] = [config('molly.agent'), config('molly.model')];

        return ['summary' => 'Add the readiness route.', 'files' => [['path' => 'routes/web.php', 'content' => readyRoute()]]];
    })->preventStrayPrompts();
    TarpitReviewer::fake([['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']), 'findings' => []]])->preventStrayPrompts();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'skipped', 'probes' => []]);

    [$exit, $run] = mollyJson('molly:start', ['task' => 'ready', '--orb' => 'big']);
    $target = $run['report']['execution_target'] ?? [];
    $events = array_map(fn (string $line): array => json_decode($line, true), file($worktree.'/.molly/lifecycle.jsonl', FILE_IGNORE_NEW_LINES));
    $types = array_column($events, 'type');
    $receipt = json_decode(File::get($worktree.'/.molly/receipts/'.$run['id'].'/pest.json'), true);

    expect($exit)->toBe(0, json_encode($run['report'] ?? $run))
        ->and($run['status'])->toBe('completed')
        ->and($seen)->toBe([['ollama', 'gpt-oss:120b-code']])
        ->and(config('molly.model'))->toBe('local-test-model')
        ->and($target)->toMatchArray([
            'schema' => 'molly.execution_target_snapshot.v1',
            'kind' => 'orb',
            'target_id' => $big['id'],
            'provider' => 'local_orb',
            'worktree' => $worktree,
            'worktree_root' => $this->orbs,
            'starting_revision' => $task->base_sha,
            'prompt_sha256' => hash('sha256', $task->prompt),
            'result' => 'completed',
        ])
        ->and($target['orb'])->toMatchArray(['id' => $big['id'], 'name' => 'big', 'device' => gethostname(), 'runtime' => 'ollama', 'model' => 'gpt-oss:120b-code', 'health' => 'healthy'])
        ->and($target['repository'])->toMatchArray(['path' => $this->repository, 'git_common_dir' => realpath($this->repository.'/.git')])
        ->and($target['started_at'] <= $target['finished_at'])->toBeTrue()
        ->and($target['diff'])->toMatchArray(['status' => 'captured', 'base' => $task->base_sha, 'paths' => ['routes/web.php', 'tests/Feature/ReadyTest.php']])
        ->and(File::get($target['diff']['path']))->toContain('diff --git a/routes/web.php b/routes/web.php', "+Route::get('/ready'")
        ->and(hash_file('sha256', $target['diff']['path']))->toBe($target['diff']['sha256'])
        ->and($run['report']['model'])->toBe('gpt-oss:120b-code')
        ->and($receipt['context']['execution_target'])->toMatchArray(['target_id' => $big['id'], 'worktree' => $worktree, 'starting_revision' => $task->base_sha])
        ->and($receipt['context']['execution_target']['diff']['sha256'])->toBe($target['diff']['sha256'])
        ->and($events[array_search('dispatch_requested', $types, true)]['payload'])->toMatchArray(['target' => 'orb', 'orb_id' => $big['id'], 'orb_name' => 'big', 'runtime' => 'ollama', 'model' => 'gpt-oss:120b-code', 'worktree' => $worktree])
        ->and($events[array_search('verification_finished', $types, true)]['payload']['execution_target'])->toMatchArray(['orb_id' => $big['id'], 'result' => 'completed'])
        ->and(Orb::findOrFail($big['id'])->current_task_id)->toBeNull()
        ->and(File::get($this->repository.'/routes/web.php'))->toBe('<?php');

    Artisan::call('molly:show', ['run' => $run['id']]);
    $shown = Artisan::output();
    Artisan::call('molly:task', ['task' => 'ready']);
    expect($shown)->toContain('Execution target: Orb big ('.$big['id'].'), ollama / gpt-oss:120b-code, worktree '.$worktree)
        ->and(Artisan::output())->toContain('orb big');

    // The web run page shows the same Orb evidence.
    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32)), 'session.driver' => 'array', 'molly.ui.enabled' => true]);
    $this->get('/molly/runs/'.$run['id'])->assertOk()
        ->assertSee('Orb big')
        ->assertSee($big['id'])
        ->assertSee('ollama / gpt-oss:120b-code')
        ->assertSee($task->base_sha)
        ->assertSee('sha256 '.$target['diff']['sha256']);
});

it('refuses to launch a task whose prompt changed after it was placed, and frees the Orb', function (): void {
    registerOrb('big', 'gpt-oss:120b-code');
    $worktree = orbTargetingWorktree('ready');
    $task = orbTargetingTask($worktree, 'ready');
    placeOnOrb($task, 'big');
    Task::whereKey($task->id)->update(['prompt' => 'Delete every route.']);
    ChangeWriter::fake()->preventStrayPrompts();

    [$exit, $run] = mollyJson('molly:start', ['task' => 'ready', '--orb' => 'big']);
    $refused = array_values(array_filter(array_map(fn (string $line): array => json_decode($line, true), file($worktree.'/.molly/lifecycle.jsonl', FILE_IGNORE_NEW_LINES)), fn (array $event): bool => $event['type'] === 'start_refused'));

    expect($exit)->toBe(1)
        ->and($run['report']['error'])->toStartWith('ORB_PROMPT_CHANGED: The prompt of task ready changed after it was placed on Orb big.')
        ->and($task->fresh()->status)->toBe('pending')
        ->and($task->fresh()->attempt_number)->toBe(0)
        ->and($task->runs()->count())->toBe(0)
        ->and(Orb::where('name', 'big')->value('current_task_id'))->toBeNull()
        ->and($refused[0]['payload']['code'])->toBe('ORB_PROMPT_CHANGED');
    ChangeWriter::assertNeverPrompted();
});

it('starts, reports, and stops a queue worker for one Orb on that Orb queue', function (): void {
    $binary = $this->repository.'-php';
    File::put($binary, "#!/bin/sh\ntrap 'exit 0' TERM\nwhile :; do sleep 1; done\n");
    chmod($binary, 0755);
    $this->cleanup[] = $binary;
    config(['molly.worker.php_binary' => $binary]);
    $big = registerOrb('big', 'gpt-oss:120b-code');

    [$exit, $started] = mollyJson('molly:worker', ['action' => 'start', '--orb' => 'big', '--workspace' => $this->repository]);
    try {
        [, $status] = mollyJson('molly:worker', ['action' => 'status', '--orb' => 'big', '--workspace' => $this->repository]);
        [, $default] = mollyJson('molly:worker', ['action' => 'status', '--workspace' => $this->repository]);
    } finally {
        [, $stopped] = mollyJson('molly:worker', ['action' => 'stop', '--orb' => 'big', '--workspace' => $this->repository, '--timeout' => 5]);
    }

    expect($exit)->toBe(0, json_encode($started))
        ->and($started)->toMatchArray(['state' => 'running', 'queue' => 'molly-orb-'.$big['id'], 'orb' => ['id' => $big['id'], 'name' => 'big']])
        ->and($started['command'])->toContain('--queue=molly-orb-'.$big['id'])
        ->and($started['pid_file'])->toBe($this->repository.'/.molly/worker/orb-'.$big['id'].'.json')
        ->and($started['log'])->toBe($this->repository.'/.molly/worker/orb-'.$big['id'].'.log')
        ->and($status)->toMatchArray(['state' => 'running', 'pid' => $started['pid']])
        ->and($default)->toMatchArray(['state' => 'stopped', 'orb' => null])
        ->and($stopped)->toMatchArray(['state' => 'stopped', 'signal' => 'SIGTERM'])
        ->and(posix_kill($started['pid'], 0))->toBeFalse();
});

it('refuses a second worker on a SQLite database queue below PHP 8.4, and starts it on PHP 8.4 or later', function (): void {
    $binary = $this->repository.'-php';
    File::put($binary, "#!/bin/sh\ntrap 'exit 0' TERM\nwhile :; do sleep 1; done\n");
    chmod($binary, 0755);
    $this->cleanup[] = $binary;
    config([
        'molly.worker.php_binary' => $binary,
        'queue.default' => 'database',
        'queue.connections.database' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 3700],
    ]);
    registerOrb('big', 'gpt-oss:120b-code');
    $small = registerOrb('small', 'gpt-oss:20b');
    $worker = fn (string $action, array $options = []): array => mollyJson('molly:worker', ['action' => $action, '--workspace' => $this->repository, ...$options]);
    $records = fn (): array => array_map(basename(...), glob($this->repository.'/.molly/worker/*.json') ?: []);

    [$bigExit, $big] = $worker('start', ['--orb' => 'big']);
    try {
        expect($bigExit)->toBe(0, json_encode($big));
        [$smallExit, $second] = $worker('start', ['--orb' => 'small']);
        [$defaultExit, $default] = $worker('start');

        if (PHP_VERSION_ID < 80400) {
            foreach ([[$smallExit, $second], [$defaultExit, $default]] as [$exit, $refused]) {
                expect($exit)->toBe(1)
                    ->and($refused['error'])->toStartWith('WORKER_CONCURRENCY_UNSUPPORTED: Molly already runs a worker in this workspace as pid '.$big['pid'])
                    ->and($refused['error'])->toContain('below PHP 8.4', 'PHP 8.4 or later', 'MySQL or PostgreSQL');
            }
            expect($records())->toBe([basename($big['pid_file'])])
                ->and(processesMentioning($binary))->toHaveCount(1);

            // A Redis queue is not affected on any PHP version.
            config(['queue.default' => 'redis', 'queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 3700]]);
            [$smallExit, $second] = $worker('start', ['--orb' => 'small']);
        } else {
            expect($defaultExit)->toBe(0, json_encode($default))
                ->and($default['state'])->toBe('running');
        }
        expect($smallExit)->toBe(0, json_encode($second))
            ->and($second)->toMatchArray(['state' => 'running', 'orb' => ['id' => $small['id'], 'name' => 'small']]);
    } finally {
        foreach ([['--orb' => 'big'], ['--orb' => 'small'], []] as $options) {
            $worker('stop', [...$options, '--timeout' => 5]);
        }
    }
});

it('refuses an Orb start through a worktree swapped for a link before it writes anything there', function (): void {
    registerOrb('big', 'gpt-oss:120b-code');
    $worktree = orbTargetingWorktree('ready');
    orbTargetingTask($worktree, 'ready');
    rename($worktree, $worktree.'-moved');
    symlink($worktree.'-moved', $worktree);
    $this->cleanup[] = $worktree.'-moved';
    $before = File::allFiles($worktree.'-moved/.molly');
    ChangeWriter::fake()->preventStrayPrompts();

    [$exit, $run] = mollyJson('molly:start', ['task' => 'ready', '--orb' => 'big']);

    expect($exit)->toBe(1)
        ->and($run['report']['error'])->toBe('ORB_WORKTREE_INVALID: The worktree '.$worktree.' for task ready now resolves to '.$worktree.'-moved. Molly runs an Orb task only in the canonical path saved with the task.')
        ->and(array_map(fn ($file) => $file->getRelativePathname(), File::allFiles($worktree.'-moved/.molly')))->toBe(array_map(fn ($file) => $file->getRelativePathname(), $before))
        ->and(Orb::where('name', 'big')->value('current_task_id'))->toBeNull();
    ChangeWriter::assertNeverPrompted();
});
