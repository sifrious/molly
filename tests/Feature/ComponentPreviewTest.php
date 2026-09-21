<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CaptureComponentPreview;
use Sifrious\Molly\Actions\CreateTask;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-preview-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/resources/views/components');
    File::put($this->workspace.'/resources/views/components/status.blade.php', '<p>Waiting</p>');
    writeProtectedTest($this->workspace, 'tests/StatusTest.php');
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('reports unavailable when no preview renderer is configured', function () {
    $result = app(CaptureComponentPreview::class)->handle($this->workspace, [
        'resources/views/components/status.blade.php' => '<p>Waiting</p>',
    ], 'before');

    expect($result)->toBe(['status' => 'unavailable', 'reason' => 'No local preview renderer is configured.']);
});

it('records a local preview digest when a renderer command produces an image', function () {
    config(['molly.preview.command' => 'cp {input} {output}', 'molly.preview.viewport' => '800x600']);

    $result = app(CaptureComponentPreview::class)->handle($this->workspace, [
        'resources/views/components/status.blade.php' => '<p>Waiting</p>',
    ], 'after');

    expect($result['status'])->toBe('captured')
        ->and($result['viewport'])->toBe('800x600')
        ->and($result['fixture'])->toBe('after')
        ->and($result['digest'])->toHaveLength(64)
        ->and($result['path'])->toBeFile()
        ->and($result['path'])->toStartWith((realpath($this->workspace) ?: $this->workspace).'/.molly/previews/');
});

it('keeps visual evidence advisory on the task creation snapshot', function () {
    config(['molly.preview.command' => 'cp {input} {output}']);
    $task = app(CreateTask::class)->handle('Show the completed state.', $this->workspace, ['resources/views/components/status.blade.php'], 'tests/StatusTest.php');

    expect($task->context_snapshot['preview']['status'])->toBe('captured')
        ->and($task->context_snapshot['preview']['digest'])->toHaveLength(64)
        ->and(json_encode($task->context_snapshot))->not->toContain('<p>Waiting</p>');
});
