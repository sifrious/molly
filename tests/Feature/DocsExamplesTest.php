<?php

use Illuminate\Support\Facades\File;
use Sifrious\Molly\Actions\RecordRedBaseline;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Models\Task;

beforeEach(function () {
    $this->workspace = laravelShapedWorkspace();
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('keeps the getting started ReadyTest identical to the tested fixture', function () {
    $documented = File::get(dirname(__DIR__, 2).'/docs/getting-started.md');

    expect($documented)->toContain(trim(File::get(dirname(__DIR__).'/Fixtures/docs/ReadyTest.php')));
});

it('runs the getting started ReadyTest red for missing behavior, then saves the documented task', function () {
    File::copy(dirname(__DIR__).'/Fixtures/docs/ReadyTest.php', $this->workspace.'/tests/Feature/ReadyTest.php');

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
