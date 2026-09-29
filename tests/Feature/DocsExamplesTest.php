<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Sifrious\Molly\Actions\RecordRedBaseline;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Jobs\StartSavedTask;
use Sifrious\Molly\Models\Task;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->workspace = laravelShapedWorkspace();
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('keeps the getting started ReadyTest identical to the tested fixture', function () {
    $documented = File::get(dirname(__DIR__, 2).'/docs/getting-started.md');

    expect($documented)->toContain(trim(File::get(dirname(__DIR__).'/Fixtures/docs/ReadyTest.example.php')));
});

it('runs the getting started ReadyTest red for missing behavior, then saves the documented task', function () {
    File::copy(dirname(__DIR__).'/Fixtures/docs/ReadyTest.example.php', $this->workspace.'/tests/Feature/ReadyTest.php');

    $verification = app(VerifyChanges::class)->handle($this->workspace, 'tests/Feature/ReadyTest.php', $this->workspace.'/evidence');
    $classification = app(RecordRedBaseline::class)->classify($verification, $this->workspace, 'tests/Feature/ReadyTest.php');

    expect($verification['reason'])->toBe('tests_failed', $verification['output'])
        ->and($verification['output'])->toContain('404')
        ->and($classification['classification'])->toBe('missing_behavior');

    [$exit, $result] = mollyJson('molly:create', [
        'prompt' => 'Add GET /ready returning exactly {"ready":true}. Preserve existing routes.',
        '--workspace' => $this->workspace,
        '--name' => 'ready-check',
        '--test' => 'tests/Feature/ReadyTest.php',
        '--file' => ['routes/web.php'],
    ]);

    expect($exit)->toBe(0)
        ->and(Task::findOrFail($result['id'])->only(['nickname', 'paths', 'test_path', 'allow_test_edits']))->toBe([
            'nickname' => 'ready-check',
            'paths' => ['routes/web.php'],
            'test_path' => 'tests/Feature/ReadyTest.php',
            'allow_test_edits' => false,
        ]);
});

it('saves the two tasks at once example in a second checkout with its own lock', function () {
    File::copy(dirname(__DIR__).'/Fixtures/docs/ReadyTest.example.php', $this->workspace.'/tests/Feature/ReadyTest.php');
    $second = $this->workspace.'-ready';
    // docs/tutorials.md: git worktree add -b ready ../app-ready, then mkdir -p and copy the untracked test in.
    (new Process(['git', 'worktree', 'add', '--quiet', '-b', 'ready', $second], $this->workspace))->mustRun();
    File::ensureDirectoryExists($second.'/tests/Feature');
    File::copy($this->workspace.'/tests/Feature/ReadyTest.php', $second.'/tests/Feature/ReadyTest.php');

    try {
        $create = fn (string $workspace, string $name): array => mollyJson('molly:create', [
            'prompt' => 'Add GET /ready returning exactly {"ready":true}. Preserve existing routes.',
            '--workspace' => $workspace,
            '--name' => $name,
            '--test' => 'tests/Feature/ReadyTest.php',
            '--file' => ['routes/web.php'],
        ]);
        [$firstExit, $first] = $create($this->workspace, 'ready-check');
        [$secondExit, $saved] = $create($second, 'ready');
        [, $tasks] = mollyJson('molly:tasks', []);
        $verification = app(VerifyChanges::class)->handle($second, 'tests/Feature/ReadyTest.php', $second.'/evidence');

        expect([$firstExit, $secondExit])->toBe([0, 0])
            ->and(Task::findOrFail($saved['id'])->workspace)->toBe(realpath($second))
            ->and(Task::findOrFail($first['id'])->workspace)->toBe(realpath($this->workspace))
            ->and(array_column($tasks['tasks'], 'nickname'))->toContain('ready', 'ready-check')
            ->and($verification['reason'])->toBe('tests_failed', $verification['output'])
            ->and($verification['output'])->toContain('404');
    } finally {
        File::deleteDirectory($second);
    }
});

it('follows the two local Orbs tutorial from worktrees to one queued task per Orb and a busy refusal', function () {
    $tutorial = File::get(dirname(__DIR__, 2).'/docs/execution-targets.md');
    $busy = 'ORB_BUSY: Orb big is working on task orb-greeting. Each Orb takes one task at a time. Wait for that task to finish, or choose another Orb.';
    foreach ([
        'git worktree add -b orb-greeting ../orbs/greeting',
        'php artisan molly:orb-register big --model=gpt-oss:120b-code --worktree-root=../orbs',
        'php artisan molly:orb-register small --model=gpt-oss:20b --worktree-root=../orbs',
        'php artisan molly:queue orb-greeting --orb=big',
        'php artisan molly:queue orb-ready --orb-model=gpt-oss:20b',
        'php artisan molly:queue demo-greeting --orb=big',
        $busy,
    ] as $documented) {
        expect($tutorial)->toContain($documented);
    }
    config(['ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434'], 'queue.default' => 'database', 'queue.connections.database.retry_after' => 3700]);
    Http::fake(['127.0.0.1:11434/api/tags' => Http::response(['models' => [['name' => 'gpt-oss:120b-code'], ['name' => 'gpt-oss:20b']]]), '127.0.0.1:11434/api/version' => Http::response(['version' => '0.12.3'])]);
    Queue::fake([StartSavedTask::class]);
    // A sibling of the workspace stands in for ../orbs, so parallel tests never share it.
    $orbs = $this->workspace.'-orbs';

    try {
        // Getting started: molly:demo and the ready test, committed so each worktree has them.
        expect(Artisan::call('molly:demo', ['--workspace' => $this->workspace, '--json' => true]))->toBe(0);
        File::copy(dirname(__DIR__).'/Fixtures/docs/ReadyTest.example.php', $this->workspace.'/tests/Feature/ReadyTest.php');
        (new Process(['git', 'add', 'app/Greeting.php', 'tests/Feature/GreetingTest.php', 'tests/Feature/ReadyTest.php'], $this->workspace))->mustRun();
        (new Process(['git', '-c', 'user.name=Molly Tests', '-c', 'user.email=tests@example.com', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'Add the greeting and ready tests'], $this->workspace))->mustRun();
        File::ensureDirectoryExists($orbs);
        (new Process(['git', 'worktree', 'add', '--quiet', '-b', 'orb-greeting', $orbs.'/greeting'], $this->workspace))->mustRun();
        (new Process(['git', 'worktree', 'add', '--quiet', '-b', 'orb-ready', $orbs.'/ready'], $this->workspace))->mustRun();

        [$greetingExit] = mollyJson('molly:create', ['prompt' => 'Return Hello from the greeting helper.', '--workspace' => $orbs.'/greeting', '--name' => 'orb-greeting', '--test' => 'tests/Feature/GreetingTest.php', '--file' => ['app/Greeting.php']]);
        [$readyExit] = mollyJson('molly:create', ['prompt' => 'Add GET /ready returning exactly {"ready":true}. Preserve existing routes.', '--workspace' => $orbs.'/ready', '--name' => 'orb-ready', '--test' => 'tests/Feature/ReadyTest.php', '--file' => ['routes/web.php']]);
        [, $big] = mollyJson('molly:orb-register', ['name' => 'big', '--model' => 'gpt-oss:120b-code', '--worktree-root' => $orbs, '--repository' => $this->workspace]);
        [, $small] = mollyJson('molly:orb-register', ['name' => 'small', '--model' => 'gpt-oss:20b', '--worktree-root' => $orbs, '--repository' => $this->workspace]);
        [, $listed] = mollyJson('molly:orbs', []);
        [, $first] = mollyJson('molly:queue', ['task' => 'orb-greeting', '--orb' => 'big']);
        [, $second] = mollyJson('molly:queue', ['task' => 'orb-ready', '--orb-model' => 'gpt-oss:20b']);
        Task::where('nickname', 'orb-greeting')->update(['status' => 'running']);
        [$refusedExit, $refused] = mollyJson('molly:queue', ['task' => 'demo-greeting', '--orb' => 'big']);

        expect([$greetingExit, $readyExit])->toBe([0, 0])
            ->and(array_column($listed['orbs'], 'health', 'name'))->toBe(['big' => 'healthy', 'small' => 'healthy'])
            ->and($first['orb']['id'])->toBe($big['orb']['id'])
            ->and($second['orb']['id'])->toBe($small['orb']['id'])
            ->and($refusedExit)->toBe(1)
            ->and($refused['error'])->toBe($busy);
        Queue::assertPushed(StartSavedTask::class, 2);
    } finally {
        File::deleteDirectory($orbs);
    }
});
