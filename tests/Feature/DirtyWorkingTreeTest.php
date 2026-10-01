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
use Sifrious\Molly\Models\Task;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->workspace = sys_get_temp_dir().'/molly-dirty-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', "<?php return null;\n");
    File::put($this->workspace.'/app/Second.php', "<?php return 2;\n");
    File::put($this->workspace.'/app/Other.php', "<?php return 'committed';\n");
    File::put($this->workspace.'/README.md', "# Example\n");
    writeProtectedTest($this->workspace);
    commitGitWorkspace($this->workspace);
    $this->workspace = realpath($this->workspace);
});

afterEach(function (): void {
    File::deleteDirectory($this->workspace);
});

/** @return list<string> the output of git in the dirty workspace */
function dirtyGit(array $arguments): string
{
    $process = new Process(['git', '-C', test()->workspace, ...$arguments]);
    $process->mustRun();

    return $process->getOutput();
}

/**
 * Leave unrelated work in the tree the way a person does: an unstaged edit, a staged
 * edit, untracked files in tracked and new directories, and an executable script.
 */
function makeUnrelatedChanges(string $workspace): void
{
    File::put($workspace.'/app/Other.php', "<?php return 'edited, not staged';\n\0binary tail");
    File::put($workspace.'/README.md', "# Example\n\nStaged notes.\n");
    dirtyGit(['add', 'README.md']);
    File::put($workspace.'/README.md', "# Example\n\nStaged notes.\n\nThen edited again.\n");
    File::put($workspace.'/app/Scratch.php', "<?php // untracked\n");
    File::ensureDirectoryExists($workspace.'/notes/deeper');
    File::put($workspace.'/notes/deeper/todo.txt', "keep me\r\n");
    File::put($workspace.'/notes/run.sh', "#!/bin/sh\necho hi\n");
    chmod($workspace.'/notes/run.sh', 0755);
}

/**
 * Every file outside .git and .molly with its bytes and mode, plus the Git index and status.
 *
 * @return array{files: array<string, array{sha256: string, mode: int}>, index: string, status: string}
 */
function treeState(string $workspace): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($workspace) + 1);
        if (str_starts_with($relative, '.git/') || str_starts_with($relative, '.molly/')) {
            continue;
        }
        $files[$relative] = ['sha256' => hash_file('sha256', $file->getPathname()), 'mode' => fileperms($file->getPathname()) & 07777];
    }
    ksort($files);

    return ['files' => $files, 'index' => dirtyGit(['ls-files', '--stage']), 'status' => dirtyGit(['status', '--porcelain=v1', '--untracked-files=all'])];
}

function dirtyTreeTask(array $paths): Task
{
    return app(CreateTask::class)->handle('Return Hello.', test()->workspace, $paths, 'tests/GreetingTest.php');
}

function fakeRunSteps(array $files, bool $verified = true): void
{
    test()->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'ok', 'probes' => []]);
    test()->mock(GenerateChanges::class)->shouldReceive('handle')->once()->andReturn(['summary' => 'Return Hello.', 'files' => $files]);
    $verify = test()->mock(VerifyChanges::class)->shouldReceive('handle');
    $review = test()->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle');
    if ($verified) {
        $verify->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true]);
        $review->once()->andReturn(['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']), 'findings' => []]);
    } else {
        $verify->never();
        $review->never();
    }
}

it('leaves unrelated dirty, staged, and untracked files byte for byte unchanged after a completed run', function (): void {
    $task = dirtyTreeTask(['app/Greeting.php']);
    makeUnrelatedChanges($this->workspace);
    $before = treeState($this->workspace);
    fakeRunSteps([['path' => 'app/Greeting.php', 'content' => "<?php return 'Hello';\n"]]);

    $run = app(StartTask::class)->handle($task->id);
    $after = treeState($this->workspace);

    $expected = $before['files'];
    $expected['app/Greeting.php']['sha256'] = hash('sha256', "<?php return 'Hello';\n");
    expect($run->status)->toBe('completed')
        ->and($after['files'])->toBe($expected)
        ->and($after['index'])->toBe($before['index'])
        ->and(explode("\n", trim($after['status'])))->toEqualCanonicalizing([...explode("\n", trim($before['status'])), ' M app/Greeting.php']);
});

it('leaves the whole tree byte for byte unchanged when a failed write is rolled back', function (): void {
    $task = dirtyTreeTask(['app/Greeting.php', 'app/Second.php']);
    makeUnrelatedChanges($this->workspace);
    $before = treeState($this->workspace);
    fakeRunSteps([
        ['path' => 'app/Greeting.php', 'content' => "<?php return 'Hello';\n"],
        ['path' => 'app/Second.php', 'content' => "<?php return 'fails';\n"],
    ], verified: false);
    $filesystem = new Filesystem;
    File::partialMock()->shouldReceive('replace')->andReturnUsing(function (string $path, string $content, ?int $mode = null) use ($filesystem): void {
        if (str_ends_with($path, '/app/Second.php') && $content !== "<?php return 2;\n") {
            throw new ErrorException('file_put_contents('.$path.'Tmp123): Failed to open stream: No space left on device');
        }
        $filesystem->replace($path, $content, $mode);
    });

    $run = app(StartTask::class)->handle($task->id);

    expect($run->status)->toBe('failed')
        ->and($run->report['error'])->toBe('WORKSPACE_WRITE_FAILED: Molly could not write app/Second.php (No space left on device). Original file contents were restored.')
        ->and(treeState($this->workspace))->toBe($before);
});
