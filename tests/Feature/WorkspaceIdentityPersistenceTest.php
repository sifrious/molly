<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;

it('persists Molly workspace identity on create and keeps path as metadata', function () {
    $workspace = sys_get_temp_dir().'/molly-identity-'.Str::uuid();
    File::ensureDirectoryExists($workspace.'/app');
    File::put($workspace.'/app/Hello.php', '<?php');
    writeProtectedTest($workspace, 'tests/Hello.php');

    $task = app(CreateTask::class)->handle('Return Hello.', $workspace, ['app/Hello.php'], 'tests/Hello.php');

    expect($task->identity_status)->toBe('bound')
        ->and($task->workspace_id)->toMatch('/^[0-9a-f-]{36}$/i')
        ->and($task->project_id)->toMatch('/^[0-9a-f-]{36}$/i')
        ->and($task->base_sha)->toMatch('/^[0-9a-f]{40}$/')
        ->and($task->workspace)->not->toBe($task->workspace_id)
        ->and(realpath($task->workspace))->toBe(realpath($workspace) ?: $workspace);

    File::deleteDirectory($workspace);
});

it('does not fabricate identity ids from the absolute path string', function () {
    $workspace = sys_get_temp_dir().'/molly-identity-'.Str::uuid();
    File::ensureDirectoryExists($workspace.'/app');
    File::put($workspace.'/app/Hello.php', '<?php');
    writeProtectedTest($workspace, 'tests/Hello.php');

    $task = app(CreateTask::class)->handle('Return Hello.', $workspace, ['app/Hello.php'], 'tests/Hello.php');

    expect($task->workspace_id)->not->toBe($workspace)
        ->and($task->project_id)->not->toBe($workspace)
        ->and($task->checkout_id)->not->toBe($workspace)
        ->and($task->repository_id)->not->toBe($workspace);

    File::deleteDirectory($workspace);
});
