<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Sifrious\Molly\Workspace;

beforeEach(function (): void {
    $this->workspaceDirectory = sys_get_temp_dir().'/molly-workspace-'.bin2hex(random_bytes(8));
    mkdir($this->workspaceDirectory.'/app', 0700, true);
    $this->workspaceDirectory = realpath($this->workspaceDirectory);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->workspaceDirectory);
});

it('keeps the required Pest test out of writable paths unless test edits are allowed', function (): void {
    mkdir($this->workspaceDirectory.'/tests', 0700, true);
    file_put_contents($this->workspaceDirectory.'/tests/GreetingTest.php', '<?php');
    $workspace = new Workspace($this->workspaceDirectory);

    expect($workspace->taskPaths(['app/File.php', 'tests/GreetingTest.php'], 'tests/GreetingTest.php'))->toBe(['app/File.php'])
        ->and($workspace->taskPaths([], 'tests/GreetingTest.php', true))->toBe(['tests/GreetingTest.php']);
    expect(fn () => $workspace->taskPaths([], 'tests/GreetingTest.php'))
        ->toThrow(RuntimeException::class, 'TEST_PROTECTED');
});

it('reads missing files and applies new file contents', function (): void {
    $workspace = new Workspace($this->workspaceDirectory);
    $before = $workspace->read(['app/NewFile.php']);

    $workspace->apply([['path' => 'app/NewFile.php', 'content' => '<?php return 1;']], $before);

    expect($before)->toBe(['app/NewFile.php' => null]);
    expect($workspace->read(['app/NewFile.php']))->toBe(['app/NewFile.php' => '<?php return 1;']);
});

it('rejects traversal and unselected directory paths', function (string $path): void {
    $workspace = new Workspace($this->workspaceDirectory);

    expect(fn () => $workspace->read([$path]))->toThrow(RuntimeException::class, 'PATH_INVALID');
})->with(['app/../outside.php', 'app/.secret', '/app/File.php', 'vendor/File.php', 'app//File.php', 'app/']);

it('rejects file and directory symlinks', function (bool $directory): void {
    file_put_contents($this->workspaceDirectory.'/original.php', 'private');
    symlink($directory ? $this->workspaceDirectory : $this->workspaceDirectory.'/original.php', $this->workspaceDirectory.'/app/link');
    $workspace = new Workspace($this->workspaceDirectory);

    expect(fn () => $workspace->read([$directory ? 'app/link/original.php' : 'app/link']))
        ->toThrow(RuntimeException::class, 'PATH_INVALID');
})->with([false, true]);

it('rejects oversized reads and proposals before writing', function (): void {
    config(['molly.max_file_bytes' => 3]);
    file_put_contents($this->workspaceDirectory.'/app/File.php', 'four');
    $workspace = new Workspace($this->workspaceDirectory);

    expect(fn () => $workspace->read(['app/File.php']))->toThrow(RuntimeException::class, 'FILE_TOO_LARGE');
    expect(fn () => $workspace->apply([['path' => 'app/New.php', 'content' => 'four']], ['app/New.php' => null]))
        ->toThrow(RuntimeException::class, 'FILE_TOO_LARGE');
    expect(file_exists($this->workspaceDirectory.'/app/New.php'))->toBeFalse();
});

it('rejects too many files', function (): void {
    config(['molly.max_files' => 1]);
    $workspace = new Workspace($this->workspaceDirectory);

    expect(fn () => $workspace->read(['app/One.php', 'app/Two.php']))->toThrow(RuntimeException::class, 'FILES_INVALID');
});

it('preserves concurrent edits instead of applying the model proposal', function (): void {
    $path = $this->workspaceDirectory.'/app/File.php';
    file_put_contents($path, 'before');
    $workspace = new Workspace($this->workspaceDirectory);
    $before = $workspace->read(['app/File.php']);
    file_put_contents($path, 'user edit');

    expect(fn () => $workspace->apply([['path' => 'app/File.php', 'content' => 'model edit']], $before))
        ->toThrow(RuntimeException::class, 'WORKSPACE_CHANGED');
    expect(file_get_contents($path))->toBe('user edit');
});

it('validates every proposal before applying the first edit', function (): void {
    file_put_contents($this->workspaceDirectory.'/app/One.php', 'before');
    $workspace = new Workspace($this->workspaceDirectory);
    $before = $workspace->read(['app/One.php', 'app/Two.php']);

    expect(fn () => $workspace->apply([
        ['path' => 'app/One.php', 'content' => 'changed'],
        ['path' => 'app/NotAllowed.php', 'content' => 'changed'],
    ], $before))->toThrow(RuntimeException::class, 'CHANGES_INVALID');
    expect(file_get_contents($this->workspaceDirectory.'/app/One.php'))->toBe('before');
});

it('restores existing bytes and removes new files after a later write fails', function (): void {
    $existing = $this->workspaceDirectory.'/app/Existing.php';
    $failing = $this->workspaceDirectory.'/app/Fail.php';
    file_put_contents($existing, "original\0bytes");
    $workspace = new Workspace($this->workspaceDirectory);
    $before = $workspace->read(['app/Existing.php', 'app/New.php', 'app/Fail.php']);
    $filesystem = new Filesystem;
    File::partialMock()->shouldReceive('replace')->andReturnUsing(function (string $path, string $content) use ($filesystem, $failing): void {
        if ($path === $failing) {
            throw new RuntimeException('Simulated disk failure.');
        }
        $filesystem->replace($path, $content);
    });

    expect(fn () => $workspace->apply([
        ['path' => 'app/Existing.php', 'content' => 'replacement'],
        ['path' => 'app/New.php', 'content' => 'new file'],
        ['path' => 'app/Fail.php', 'content' => 'fails'],
    ], $before))->toThrow(RuntimeException::class, 'WORKSPACE_WRITE_FAILED');
    expect($workspace->read(array_keys($before)))->toBe($before);
});

it('names files that could not be restored', function (): void {
    $existing = $this->workspaceDirectory.'/app/Existing.php';
    $failing = $this->workspaceDirectory.'/app/Fail.php';
    file_put_contents($existing, 'original');
    $workspace = new Workspace($this->workspaceDirectory);
    $before = $workspace->read(['app/Existing.php', 'app/Fail.php']);
    $filesystem = new Filesystem;
    File::partialMock()->shouldReceive('replace')->andReturnUsing(function (string $path, string $content) use ($filesystem, $failing): void {
        if ($path === $failing || $content === 'original') {
            throw new RuntimeException('Simulated disk failure.');
        }
        $filesystem->replace($path, $content);
    });

    expect(fn () => $workspace->apply([
        ['path' => 'app/Existing.php', 'content' => 'replacement'],
        ['path' => 'app/Fail.php', 'content' => 'fails'],
    ], $before))->toThrow(RuntimeException::class, 'WORKSPACE_ROLLBACK_FAILED: Review these files before continuing: app/Existing.php');
    expect(file_get_contents($existing))->toBe('replacement');
});

it('blocks overlapping locks across workspace instances', function (): void {
    $first = new Workspace($this->workspaceDirectory);
    $second = new Workspace($this->workspaceDirectory);

    $result = $first->exclusively(function () use ($second): string {
        expect(fn () => $second->exclusively(fn () => 'unexpected'))->toThrow(RuntimeException::class, 'WORKSPACE_BUSY');

        return 'finished';
    });

    expect($result)->toBe('finished');
    expect($second->exclusively(fn () => 'next run'))->toBe('next run');
});

it('releases the workspace lock when the callback throws', function (): void {
    $workspace = new Workspace($this->workspaceDirectory);

    expect(fn () => $workspace->exclusively(fn () => throw new RuntimeException('Task failed.')))
        ->toThrow(RuntimeException::class, 'Task failed.');
    expect((new Workspace($this->workspaceDirectory))->exclusively(fn () => 'next run'))->toBe('next run');
});

it('rejects symlinked lock files and lock directories', function (bool $directory): void {
    if ($directory) {
        symlink($this->workspaceDirectory.'/app', $this->workspaceDirectory.'/.molly');
    } else {
        mkdir($this->workspaceDirectory.'/.molly');
        file_put_contents($this->workspaceDirectory.'/app/lock', '');
        symlink($this->workspaceDirectory.'/app/lock', $this->workspaceDirectory.'/.molly/run.lock');
    }
    $workspace = new Workspace($this->workspaceDirectory);

    expect(fn () => $workspace->exclusively(fn () => 'unexpected'))->toThrow(RuntimeException::class, 'WORKSPACE_LOCK_INVALID');
})->with([false, true]);

it('rejects invalid lock locations without invoking the callback', function (bool $directoryIsFile): void {
    if ($directoryIsFile) {
        file_put_contents($this->workspaceDirectory.'/.molly', 'not a directory');
    } else {
        mkdir($this->workspaceDirectory.'/.molly/run.lock', 0700, true);
    }
    $workspace = new Workspace($this->workspaceDirectory);

    expect(fn () => $workspace->exclusively(fn () => 'unexpected'))->toThrow(RuntimeException::class, 'WORKSPACE_LOCK_INVALID');
})->with([false, true]);

it('keeps the file mode of a selected file when applying a proposal', function () {
    $workspace = new Workspace($this->workspaceDirectory);
    $path = $this->workspaceDirectory.'/app/Greeting.php';
    file_put_contents($path, '<?php return "original";');
    chmod($path, 0644);
    $before = $workspace->read(['app/Greeting.php']);

    $workspace->apply([['path' => 'app/Greeting.php', 'content' => '<?php return "changed";']], $before);

    expect(fileperms($path) & 0777)->toBe(0644)
        ->and(file_get_contents($path))->toBe('<?php return "changed";');
});
