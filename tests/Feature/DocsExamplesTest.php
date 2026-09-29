<?php

use Illuminate\Support\Facades\File;
use Sifrious\Molly\Actions\RecordRedBaseline;
use Sifrious\Molly\Actions\VerifyChanges;
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
