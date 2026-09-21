<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;

it('binds identity by initializing git when a fresh app has no checkout', function () {
    $workspace = sys_get_temp_dir().'/molly-nogit-'.Str::uuid();
    File::ensureDirectoryExists($workspace.'/app');
    File::ensureDirectoryExists($workspace.'/tests');
    File::put($workspace.'/app/Hello.php', '<?php');
    File::put($workspace.'/tests/HelloTest.php', "<?php\nit('works', fn () => expect(true)->toBeTrue());\n");
    expect(File::exists($workspace.'/.git'))->toBeFalse();

    $task = app(CreateTask::class)->handle('Return Hello.', $workspace, ['app/Hello.php'], 'tests/HelloTest.php');

    expect($task->identity_status)->toBe('bound')
        ->and($task->base_sha)->toMatch('/^[0-9a-f]{40}$/')
        ->and(File::isDirectory($workspace.'/.git') || File::isFile($workspace.'/.git'))->toBeTrue();

    File::deleteDirectory($workspace);
});
