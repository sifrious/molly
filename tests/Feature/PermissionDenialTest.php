<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Workspace;

beforeEach(function (): void {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('The superuser can write read-only files, so permission denial cannot be observed.');
    }
    $this->workspace = sys_get_temp_dir().'/molly-permission-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    $this->workspace = realpath($this->workspace);
});

afterEach(function (): void {
    if (isset($this->workspace)) {
        exec('chmod -R u+w '.escapeshellarg($this->workspace));
        File::deleteDirectory($this->workspace);
    }
});

/** @return list<string> Every file and directory under $directory, relative to it. */
function filesUnder(string $directory): array
{
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $found[] = substr($file->getPathname(), strlen($directory) + 1);
    }
    sort($found);

    return $found;
}

it('names a read-only selected file and the reason before writing anything', function (): void {
    File::put($this->workspace.'/app/Existing.php', 'original');
    File::put($this->workspace.'/app/Locked.php', 'locked');
    chmod($this->workspace.'/app/Locked.php', 0444);
    $workspace = new Workspace($this->workspace);
    $before = $workspace->read(['app/Existing.php', 'app/New.php', 'app/Locked.php']);

    expect(fn () => $workspace->apply([
        ['path' => 'app/Existing.php', 'content' => 'replacement'],
        ['path' => 'app/New.php', 'content' => 'new file'],
        ['path' => 'app/Locked.php', 'content' => 'changed'],
    ], $before))->toThrow(RuntimeException::class, 'WORKSPACE_WRITE_FAILED: Molly cannot write app/Locked.php (Permission denied). No files were changed.');

    expect($workspace->read(array_keys($before)))->toBe($before)
        ->and(fileperms($this->workspace.'/app/Locked.php') & 0777)->toBe(0444)
        ->and(filesUnder($this->workspace))->toBe(['app', 'app/Existing.php', 'app/Locked.php']);
});

it('names a read-only directory that would hold a selected file', function (string $path, string $directory): void {
    File::ensureDirectoryExists($this->workspace.'/app/Models');
    chmod($this->workspace.'/'.$directory, 0555);
    $workspace = new Workspace($this->workspace);
    $before = $workspace->read([$path]);

    expect(fn () => $workspace->apply([['path' => $path, 'content' => 'new file']], $before))
        ->toThrow(RuntimeException::class, 'WORKSPACE_WRITE_FAILED: Molly cannot write '.$path.' because it cannot write the directory '.$directory.' (Permission denied). No files were changed. Make '.$directory.' writable by this user');

    chmod($this->workspace.'/'.$directory, 0755);
    expect(filesUnder($this->workspace))->toBe(['app', 'app/Models']);
})->with([
    'new file' => ['app/Models/User.php', 'app/Models'],
    'new directory' => ['app/Models/Concerns/HasName.php', 'app/Models'],
]);

it('names the file and the system reason when a write fails partway and restores the rest', function (): void {
    File::put($this->workspace.'/app/Existing.php', 'original');
    $workspace = new Workspace($this->workspace);
    $before = $workspace->read(['app/Existing.php', 'app/Full.php']);
    $filesystem = new Filesystem;
    File::partialMock()->shouldReceive('replace')->andReturnUsing(function (string $path, string $content, ?int $mode = null) use ($filesystem): void {
        if (str_ends_with($path, '/app/Full.php')) {
            throw new ErrorException('file_put_contents('.$path.'Xy12ab): Failed to open stream: No space left on device');
        }
        $filesystem->replace($path, $content, $mode);
    });

    expect(fn () => $workspace->apply([
        ['path' => 'app/Existing.php', 'content' => 'replacement'],
        ['path' => 'app/Full.php', 'content' => 'new file'],
    ], $before))->toThrow(RuntimeException::class, 'WORKSPACE_WRITE_FAILED: Molly could not write app/Full.php (No space left on device). Original file contents were restored.');

    expect($workspace->read(array_keys($before)))->toBe($before);
});

it('fails the run with the unwritable file and reason and leaves the file unchanged', function (): void {
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($this->workspace);
    commitGitWorkspace($this->workspace);
    chmod($this->workspace.'/app/Greeting.php', 0444);
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(GenerateChanges::class)->shouldReceive('handle')->once()->andReturn([
        'summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']],
    ]);
    $this->mock(VerifyChanges::class)->shouldNotReceive('handle');
    $this->mock(ReviewChanges::class)->makePartial()->shouldNotReceive('handle');

    $run = app(StartTask::class)->handle($task->id);

    expect($run->status)->toBe('failed')
        ->and($run->report['error'])->toBe('WORKSPACE_WRITE_FAILED: Molly cannot write app/Greeting.php (Permission denied). No files were changed. Make app/Greeting.php writable by this user, then retry the task.')
        ->and($run->report['changes'])->toBe([])
        ->and($task->fresh()->status)->toBe('failed')
        ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe('<?php return null;')
        ->and(glob($this->workspace.'/app/*'))->toBe([$this->workspace.'/app/Greeting.php']);
});
