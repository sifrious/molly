<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\ExportTaskJournal;
use Sifrious\Molly\Actions\NameTask;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Journal\JournalWriter;
use Sifrious\Molly\Models\Task;

beforeEach(function (): void {
    $this->journalWorkspace = sys_get_temp_dir().'/molly-journal-'.Str::uuid();
    mkdir($this->journalWorkspace, 0700);
    $this->journalWorkspace = realpath($this->journalWorkspace);
});

afterEach(function (): void {
    File::deleteDirectory($this->journalWorkspace);
});

function journalTask(array $attributes = []): Task
{
    return Task::create([
        'nickname' => 'health-check',
        'prompt' => 'Add a health endpoint.',
        'workspace' => test()->journalWorkspace,
        'paths' => ['routes/web.php', 'tests/Feature/HealthTest.php'],
        'test_path' => 'tests/Feature/HealthTest.php',
        ...$attributes,
    ]);
}

it('replaces one journal by UUID and retains attempts after a task rename', function (): void {
    $this->travelTo('2026-09-17 10:00:00');
    $task = journalTask(['status' => 'failed']);
    $first = $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => ['error' => 'TESTS_FAILED: A required test failed.']]);
    $exporter = app(ExportTaskJournal::class);
    $initial = $exporter->handle('HEALTH-CHECK');
    $initialContents = File::get($initial['path']);

    $repeated = $exporter->handle($task->id);

    expect($repeated)->toBe(['path' => $this->journalWorkspace.'/.molly/journal/'.$task->id.'.md', 'task_id' => $task->id, 'attempt_count' => 1])
        ->and(File::get($initial['path']))->toBe($initialContents);

    $this->travel(1)->minute();
    app(NameTask::class)->handle($task->id, 'renamed-check');
    $second = $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'completed', 'report' => ['verification' => ['status' => 'passed', 'tests' => 1, 'assertions' => 2]]]);
    $task->update(['status' => 'completed']);

    $renamed = $exporter->handle('renamed-check');
    $html = Str::markdown(File::get($renamed['path']));

    expect($renamed['path'])->toBe($initial['path'])
        ->and($renamed['attempt_count'])->toBe(2)
        ->and(glob($this->journalWorkspace.'/.molly/journal/*.md'))->toBe([$initial['path']])
        ->and($html)->toContain('renamed-check', $first->id, $second->id, 'Status: failed', 'Status: completed', '2026-09-17T10:00:00', '2026-09-17T10:01:00')
        ->and(substr_count($html, $first->id))->toBe(1)
        ->and(strpos($html, $first->id))->toBeLessThan(strpos($html, $second->id));
});

it('exports test failures and Tarpit evidence without changing lifecycle records or copying source and credentials', function (): void {
    $task = journalTask(['status' => 'running', 'stop_requested_at' => now(), 'source' => ['token' => 'source-secret'], 'context_snapshot' => ['files' => ['routes/web.php' => 'snapshot-source-secret']]]);
    File::put($this->journalWorkspace.'/.env', 'PROVIDER_API_KEY=environment-secret');
    $run = $task->runs()->create([
        'prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed',
        'report' => [
            'error' => 'VERIFICATION_FAILED: The required test did not pass.',
            'verification' => ['status' => 'failed', 'tests' => 3, 'assertions' => 8, 'failures' => 1, 'errors' => 0, 'skipped' => 2, 'reason' => 'An assertion failed.', 'output' => 'full-output-secret'],
            'review' => [
                'checks' => ['E' => ['status' => 'findings', 'evidence' => 'A wrapper adds an unused call.']],
                'findings' => [['code' => 'E', 'severity' => 'blocking', 'classification' => 'accidental', 'path' => 'routes/web.php', 'line' => 12, 'problem' => 'One extra call has no purpose.', 'recommendation' => 'Return the response directly.']],
            ],
            'complexity_before' => ['status' => 'skipped', 'reason' => 'No Git baseline exists.'],
            'complexity_after' => ['status' => 'completed', 'probes' => [['key' => 'c4', 'name' => 'Hotspots', 'status' => 'ok', 'metrics' => ['hotspot_count' => 2]]]],
            'files' => [['content' => 'full-source-secret']],
            'provider' => ['api_key' => 'provider-secret'],
        ],
    ]);
    $taskBefore = $task->fresh()->getRawOriginal();
    $runBefore = $run->fresh()->getRawOriginal();

    $result = app(ExportTaskJournal::class)->handle($task->id);
    $html = Str::markdown(File::get($result['path']));

    expect($html)->toContain('VERIFICATION_FAILED', 'Tests: 3', 'Assertions: 8', 'Failures: 1', 'Errors: 0', 'Skipped: 2', 'An assertion failed.', 'E: findings', 'A: Not recorded', 'G: Not recorded', 'blocking / accidental', 'routes/web.php:12', 'Return the response directly.', 'Before changes: skipped', 'No Git baseline exists.', 'hotspot count: 2')
        ->not->toContain('source-secret', 'snapshot-source-secret', 'environment-secret', 'full-output-secret', 'full-source-secret', 'provider-secret')
        ->and($task->fresh()->getRawOriginal())->toBe($taskBefore)
        ->and($run->fresh()->getRawOriginal())->toBe($runBefore)
        ->and(fileperms($result['path']) & 0777)->toBe(0600);
});

it('renders untrusted descriptions as quoted text without active HTML or links', function (): void {
    $payload = "<script>alert('xss')</script>\n![image](https://example.com/track)\n[link](javascript:alert(1))\n# Forged heading\n```html\n<img src=x onerror=alert(1)>\n```";
    $task = journalTask(['prompt' => $payload]);
    $task->runs()->create(['prompt' => $payload, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => ['summary' => $payload, 'error' => $payload, 'review' => ['checks' => ['A' => ['status' => 'findings', 'evidence' => $payload]], 'findings' => [['path' => $payload, 'line' => 1, 'problem' => $payload, 'recommendation' => $payload]]]]]);

    $result = app(ExportTaskJournal::class)->handle($task->id);
    $html = Str::markdown(File::get($result['path']));

    expect($html)->not->toContain('<script', '<img', '<a ', '<h1>Forged heading')
        ->toContain('<blockquote>', '&lt;script&gt;', 'Forged heading');
});

it('exports recorded pull request, merge SHA, and lifecycle events without copying local paths', function (): void {
    $task = journalTask([
        'status' => 'completed',
        'source' => [
            'issue_url' => 'https://github.com/sifrious/molly/issues/42',
            'linked_pr' => [
                'url' => 'https://github.com/sifrious/molly/pull/12',
                'number' => 12,
                'merge_sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            ],
        ],
    ]);
    $lifecycle = app(RecordLifecycleEvent::class);
    $lifecycle->handle($this->journalWorkspace, LifecycleEventType::Created, $task->id);
    $lifecycle->handle($this->journalWorkspace, LifecycleEventType::ApprovalResolved, $task->id);
    $lifecycle->handle($this->journalWorkspace, LifecycleEventType::PullRequestOpened, $task->id, null, [
        'url' => 'https://github.com/sifrious/molly/pull/12',
        'opened' => false,
    ]);
    $lifecycle->handle($this->journalWorkspace, LifecycleEventType::Merged, $task->id, null, [
        'url' => 'https://github.com/sifrious/molly/pull/12',
        'sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'merged' => false,
    ]);

    $result = app(ExportTaskJournal::class)->handle($task->id);
    $markdown = File::get($result['path']);

    expect($markdown)->toContain('Recorded pull request: https://github\\.com/sifrious/molly/pull/12')
        ->toContain('Recorded merge SHA: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->toContain('Display status: merged')
        ->toContain('Lifecycle: pull\\_request\\_opened https://github\\.com/sifrious/molly/pull/12')
        ->toContain('Lifecycle: merged https://github\\.com/sifrious/molly/pull/12 aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
        ->not->toContain($this->journalWorkspace);
});

it('exports a pending task through JSON without creating an attempt', function (): void {
    $task = journalTask();

    $exit = Artisan::call('molly:journal', ['task' => 'health-check', '--json' => true, '--no-interaction' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($result)->toBe(['path' => $this->journalWorkspace.'/.molly/journal/'.$task->id.'.md', 'task_id' => $task->id, 'attempt_count' => 0])
        ->and(File::get($result['path']))->toContain('No attempts recorded.')
        ->and($task->fresh()->status)->toBe('pending')
        ->and($task->runs()->count())->toBe(0);
});

it('prints a concise confirmation after saving a journal', function (): void {
    $task = journalTask();

    $this->artisan('molly:journal', ['task' => $task->id])
        ->expectsOutputToContain('Journal saved: '.$this->journalWorkspace.'/.molly/journal/'.$task->id.'.md')
        ->expectsOutputToContain('0 saved attempts.')
        ->assertSuccessful();
});

it('reports an unknown task without creating a journal directory', function (): void {
    $exit = Artisan::call('molly:journal', ['task' => 'missing', '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($result)->toBe(['task' => 'missing', 'status' => 'error', 'error' => 'TASK_NOT_FOUND: No saved task has that name or ID.'])
        ->and(is_dir($this->journalWorkspace.'/.molly'))->toBeFalse();
});

it('rejects symbolic links anywhere in the journal path without altering their targets', function (string $location): void {
    $task = journalTask(['status' => 'failed']);
    $outside = $this->journalWorkspace.'/outside';
    mkdir($outside);
    File::put($outside.'/untouched.md', 'Keep this file.');
    $link = $this->journalWorkspace.'/'.$location;
    if ($location === 'file') {
        $link = $this->journalWorkspace.'/.molly/journal/'.$task->id.'.md';
    }
    File::ensureDirectoryExists(dirname($link));
    symlink($location === 'file' ? $outside.'/untouched.md' : $outside, $link);
    $before = $task->fresh()->getRawOriginal();

    expect(fn () => app(ExportTaskJournal::class)->handle($task->id))->toThrow(RuntimeException::class, 'JOURNAL_PATH_INVALID');

    expect(File::get($outside.'/untouched.md'))->toBe('Keep this file.')
        ->and(scandir($outside))->toBe(['.', '..', 'untouched.md'])
        ->and($task->fresh()->getRawOriginal())->toBe($before);
    unlink($link);
})->with(['metadata directory' => '.molly', 'journal directory' => '.molly/journal', 'journal file' => 'file']);

it('rejects files in place of journal directories', function (string $location): void {
    $task = journalTask();
    $path = $this->journalWorkspace.'/'.$location;
    File::ensureDirectoryExists(dirname($path));
    File::put($path, 'Keep this file.');

    expect(fn () => app(ExportTaskJournal::class)->handle($task->id))->toThrow(RuntimeException::class, 'JOURNAL_PATH_INVALID');

    expect(File::get($path))->toBe('Keep this file.');
})->with(['.molly', '.molly/journal']);

it('rejects a directory or hard link at the journal destination', function (bool $hardLink): void {
    $task = journalTask();
    $path = $this->journalWorkspace.'/.molly/journal/'.$task->id.'.md';
    mkdir(dirname($path), 0700, true);
    if ($hardLink) {
        File::put($this->journalWorkspace.'/original.md', 'Keep this file.');
        link($this->journalWorkspace.'/original.md', $path);
    } else {
        mkdir($path);
    }

    expect(fn () => app(ExportTaskJournal::class)->handle($task->id))->toThrow(RuntimeException::class, 'JOURNAL_PATH_INVALID');

    if ($hardLink) {
        expect(File::get($path))->toBe('Keep this file.');
    } else {
        expect(is_dir($path))->toBeTrue();
    }
})->with(['hard link' => true, 'directory' => false]);

it('preserves the previous journal and saved records when a write cannot finish', function (): void {
    $task = journalTask(['status' => 'running']);
    $run = $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'running', 'report' => []]);
    $initial = app(ExportTaskJournal::class)->handle($task->id);
    $original = File::get($initial['path']);
    $taskBefore = $task->fresh()->getRawOriginal();
    $runBefore = $run->fresh()->getRawOriginal();
    $real = app(JournalWriter::class);
    $this->mock(JournalWriter::class, function ($mock) use ($real): void {
        $mock->shouldReceive('prepareMollyDirectory')->andReturnUsing(fn (string $root) => $real->prepareMollyDirectory($root));
        $mock->shouldReceive('ensureDirectory')->andReturnUsing(fn (string $path) => $real->ensureDirectory($path));
        $mock->shouldReceive('validateFile')->andReturnUsing(fn (string $path) => $real->validateFile($path));
        $mock->shouldReceive('replaceFile')->once()->andThrow(new RuntimeException('JOURNAL_WRITE_FAILED: The journal could not be written in full.'));
    });

    $exit = Artisan::call('molly:journal', ['task' => 'health-check', '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($result['status'])->toBe('error')
        ->and($result['error'])->toContain('JOURNAL_WRITE_FAILED')
        ->and($result)->not->toHaveKey('path')
        ->and(File::get($initial['path']))->toBe($original)
        ->and(scandir(dirname($initial['path'])))->toBe(['.', '..', $task->id.'.md'])
        ->and($task->fresh()->getRawOriginal())->toBe($taskBefore)
        ->and($run->fresh()->getRawOriginal())->toBe($runBefore);
});

it('rebuilds a project chronology without duplicating entries or including another workspace', function (): void {
    $this->travelTo('2026-09-17 10:00:00');
    $firstTask = journalTask();
    $this->travel(1)->minute();
    $secondTask = journalTask(['nickname' => 'second-task']);
    $this->travel(1)->minute();
    $firstRun = $firstTask->runs()->create(['prompt' => $firstTask->prompt, 'workspace' => $firstTask->workspace, 'status' => 'failed', 'report' => ['error' => 'First attempt failed.']]);
    $this->travel(1)->minute();
    $secondRun = $secondTask->runs()->create(['prompt' => $secondTask->prompt, 'workspace' => $secondTask->workspace, 'status' => 'completed', 'report' => []]);
    $outside = journalTask(['nickname' => 'outside-task', 'workspace' => '/another-workspace', 'prompt' => 'Outside task must stay out.']);
    $exporter = app(ExportTaskJournal::class);
    $first = $exporter->forWorkspace($this->journalWorkspace.'/.');
    $original = File::get($first['journal_path']);
    $glossary = File::get($first['glossary_path']);

    $repeated = $exporter->forWorkspace($this->journalWorkspace);
    $html = Str::markdown(File::get($repeated['journal_path']));

    expect($repeated)->toBe(['journal_path' => $this->journalWorkspace.'/.molly/JOURNAL.md', 'glossary_path' => $this->journalWorkspace.'/.molly/GLOSSARY.md', 'task_count' => 2, 'attempt_count' => 2])
        ->and(File::get($repeated['journal_path']))->toBe($original)
        ->and(File::get($repeated['glossary_path']))->toBe($glossary)
        ->and($glossary)->toContain('Task:', 'Attempt:', 'Tarpit review:', 'Accidental complexity:', 'Clever measurements:', 'Recorded pull request:', 'Recorded merge:')
        ->and($html)->toContain($firstTask->id, $secondTask->id, 'First attempt failed.')
        ->not->toContain($outside->id, 'Outside task must stay out.')
        ->and(strpos($html, $secondTask->id))->toBeLessThan(strpos($html, $firstRun->id))
        ->and(strpos($html, $firstRun->id))->toBeLessThan(strpos($html, $secondRun->id))
        ->and(substr_count($html, $firstRun->id))->toBe(1)
        ->and(substr_count($html, $secondRun->id))->toBe(1);

    app(NameTask::class)->handle($firstTask->id, 'renamed-task');
    $exporter->forWorkspace($this->journalWorkspace);
    $renamed = Str::markdown(File::get($first['journal_path']));

    expect($renamed)->toContain('renamed-task', $firstRun->id)
        ->not->toContain('health-check')
        ->and($firstRun->fresh()->task_id)->toBe($firstTask->id);
});

it('writes an empty project journal without creating database records', function (): void {
    $result = app(ExportTaskJournal::class)->forWorkspace($this->journalWorkspace);

    expect($result['task_count'])->toBe(0)
        ->and($result['attempt_count'])->toBe(0)
        ->and(File::get($result['journal_path']))->toContain('No tasks recorded.')
        ->and(Task::count())->toBe(0);
});

it('rejects linked project artifacts without overwriting their targets', function (string $filename): void {
    mkdir($this->journalWorkspace.'/.molly', 0700);
    $outside = $this->journalWorkspace.'/outside.md';
    File::put($outside, 'Keep this file.');
    symlink($outside, $this->journalWorkspace.'/.molly/'.$filename);

    expect(fn () => app(ExportTaskJournal::class)->forWorkspace($this->journalWorkspace))->toThrow(RuntimeException::class, 'JOURNAL_PATH_INVALID');

    expect(File::get($outside))->toBe('Keep this file.');
    unlink($this->journalWorkspace.'/.molly/'.$filename);
})->with(['JOURNAL.md', 'GLOSSARY.md']);

it('keeps the previous project journal and task state when replacement fails', function (): void {
    $task = journalTask(['status' => 'running']);
    $initial = app(ExportTaskJournal::class)->forWorkspace($this->journalWorkspace);
    $original = File::get($initial['journal_path']);
    $before = $task->fresh()->getRawOriginal();
    $real = app(JournalWriter::class);
    $journalPath = $initial['journal_path'];
    $this->mock(JournalWriter::class, function ($mock) use ($real, $journalPath): void {
        $mock->shouldReceive('prepareMollyDirectory')->andReturnUsing(fn (string $root) => $real->prepareMollyDirectory($root));
        $mock->shouldReceive('ensureDirectory')->andReturnUsing(fn (string $path) => $real->ensureDirectory($path));
        $mock->shouldReceive('validateFile')->andReturnUsing(fn (string $path) => $real->validateFile($path));
        $mock->shouldReceive('replaceFile')->andReturnUsing(function (string $path, string $contents, string|false|null $expectedHash = null) use ($real, $journalPath): void {
            if ($path === $journalPath) {
                throw new RuntimeException('JOURNAL_WRITE_FAILED: The journal could not be replaced.');
            }
            $real->replaceFile($path, $contents, $expectedHash);
        });
    });

    expect(fn () => app(ExportTaskJournal::class)->forWorkspace($this->journalWorkspace))->toThrow(RuntimeException::class, 'JOURNAL_WRITE_FAILED');

    expect(File::get($initial['journal_path']))->toBe($original)
        ->and(scandir($this->journalWorkspace.'/.molly'))->toBe(['.', '..', '.gitignore', 'GLOSSARY.md', 'JOURNAL.md'])
        ->and($task->fresh()->getRawOriginal())->toBe($before);
});

it('updates only the Molly glossary section and preserves custom ignore rules', function (): void {
    mkdir($this->journalWorkspace.'/.molly', 0700);
    $glossaryPath = $this->journalWorkspace.'/.molly/GLOSSARY.md';
    $ignorePath = $this->journalWorkspace.'/.molly/.gitignore';
    $customGlossary = "# Our project terms\n\n- Account: The customer who owns a subscription.\n";
    $customIgnore = "# Existing shared rules\ncache/\n!GLOSSARY.md\n";
    File::put($glossaryPath, $customGlossary);
    File::put($ignorePath, $customIgnore);
    $exporter = app(ExportTaskJournal::class);
    $exporter->forWorkspace($this->journalWorkspace);
    $firstGlossary = File::get($glossaryPath);
    File::append($glossaryPath, "\n- Billing cycle: A monthly invoice period.\n");

    $exporter->forWorkspace($this->journalWorkspace);

    expect(File::get($glossaryPath))->toBe($firstGlossary."\n- Billing cycle: A monthly invoice period.\n")
        ->toStartWith($customGlossary)
        ->and(substr_count(File::get($glossaryPath), '<!-- molly:glossary:start -->'))->toBe(1)
        ->and(File::get($ignorePath))->toBe($customIgnore."*\n");
});

it('refuses an incomplete generated glossary section without changing custom content', function (): void {
    mkdir($this->journalWorkspace.'/.molly', 0700);
    $path = $this->journalWorkspace.'/.molly/GLOSSARY.md';
    $custom = "# Our glossary\n\n<!-- molly:glossary:start -->\nKeep this custom definition.\n";
    File::put($path, $custom);

    expect(fn () => app(ExportTaskJournal::class)->forWorkspace($this->journalWorkspace))->toThrow(RuntimeException::class, 'JOURNAL_WRITE_FAILED');

    expect(File::get($path))->toBe($custom);
});

it('rejects a linked ignore file without changing the link target', function (): void {
    $task = journalTask();
    mkdir($this->journalWorkspace.'/.molly', 0700);
    $outside = $this->journalWorkspace.'/shared-ignore';
    File::put($outside, 'Keep these rules.');
    symlink($outside, $this->journalWorkspace.'/.molly/.gitignore');

    expect(fn () => app(ExportTaskJournal::class)->handle($task->id))->toThrow(RuntimeException::class, 'JOURNAL_PATH_INVALID');

    expect(File::get($outside))->toBe('Keep these rules.');
    unlink($this->journalWorkspace.'/.molly/.gitignore');
});

it('preserves a glossary edited during export instead of replacing the new content', function (): void {
    mkdir($this->journalWorkspace.'/.molly', 0700);
    $path = $this->journalWorkspace.'/.molly/GLOSSARY.md';
    File::put($path, '# Initial glossary');
    $real = app(JournalWriter::class);
    $this->mock(JournalWriter::class, function ($mock) use ($real, $path): void {
        $mock->shouldReceive('prepareMollyDirectory')->andReturnUsing(fn (string $root) => $real->prepareMollyDirectory($root));
        $mock->shouldReceive('ensureDirectory')->andReturnUsing(fn (string $dir) => $real->ensureDirectory($dir));
        $mock->shouldReceive('validateFile')->andReturnUsing(fn (string $file) => $real->validateFile($file));
        $mock->shouldReceive('replaceFile')->andReturnUsing(function (string $file, string $contents, string|false|null $expectedHash = null) use ($real, $path): void {
            if ($file === $path && is_string($expectedHash) && str_contains($contents, '<!-- molly:glossary:start -->')) {
                file_put_contents($path, '# Concurrent glossary edit');
            }
            $real->replaceFile($file, $contents, $expectedHash);
        });
    });

    expect(fn () => app(ExportTaskJournal::class)->forWorkspace($this->journalWorkspace))->toThrow(RuntimeException::class, 'JOURNAL_WRITE_FAILED');

    expect(File::get($path))->toBe('# Concurrent glossary edit')
        ->and(scandir($this->journalWorkspace.'/.molly'))->toBe(['.', '..', '.gitignore', 'GLOSSARY.md']);
});

it('proves journal output and security remain byte-compatible after JournalWriter migration', function (): void {
    $this->travelTo('2026-09-17 10:00:00');
    $task = journalTask([
        'status' => 'failed',
        'prompt' => "<script>alert('xss')</script>\n[link](javascript:alert(1))",
        'source' => [
            'token' => 'source-secret',
            'issue_url' => 'https://github.com/sifrious/molly/issues/42',
            'linked_pr' => [
                'url' => 'https://github.com/sifrious/molly/pull/12',
                'merge_sha' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            ],
        ],
        'context_snapshot' => ['files' => ['routes/web.php' => 'snapshot-source-secret']],
    ]);
    File::put($this->journalWorkspace.'/.env', 'PROVIDER_API_KEY=environment-secret');
    $run = $task->runs()->create([
        'prompt' => $task->prompt,
        'workspace' => $task->workspace,
        'status' => 'failed',
        'report' => [
            'error' => 'VERIFICATION_FAILED: The required test did not pass.',
            'verification' => [
                'status' => 'failed',
                'tests' => 1,
                'assertions' => 1,
                'failures' => 1,
                'errors' => 0,
                'skipped' => 0,
                'reason' => 'An assertion failed.',
                'output' => 'full-output-secret',
            ],
            'files' => [['content' => 'full-source-secret']],
            'provider' => ['api_key' => 'provider-secret'],
        ],
    ]);
    app(RecordLifecycleEvent::class)->handle($this->journalWorkspace, LifecycleEventType::Created, $task->id);
    app(RecordLifecycleEvent::class)->handle($this->journalWorkspace, LifecycleEventType::Merged, $task->id, null, [
        'url' => 'https://github.com/sifrious/molly/pull/12',
        'sha' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'merged' => false,
    ]);

    $exporter = app(ExportTaskJournal::class);
    $firstTask = $exporter->handle($task->id);
    $firstProject = $exporter->forWorkspace($this->journalWorkspace);
    $taskMarkdown = File::get($firstTask['path']);
    $projectMarkdown = File::get($firstProject['journal_path']);
    $glossaryMarkdown = File::get($firstProject['glossary_path']);
    $taskBefore = $task->fresh()->getRawOriginal();
    $runBefore = $run->fresh()->getRawOriginal();

    $secondTask = $exporter->handle($task->id);
    $secondProject = $exporter->forWorkspace($this->journalWorkspace);

    expect($secondTask)->toBe($firstTask)
        ->and($secondProject)->toBe($firstProject)
        ->and(File::get($secondTask['path']))->toBe($taskMarkdown)
        ->and(File::get($secondProject['journal_path']))->toBe($projectMarkdown)
        ->and(File::get($secondProject['glossary_path']))->toBe($glossaryMarkdown)
        ->and(fileperms($this->journalWorkspace.'/.molly') & 0777)->toBe(0700)
        ->and(fileperms($this->journalWorkspace.'/.molly/journal') & 0777)->toBe(0700)
        ->and(fileperms($firstTask['path']) & 0777)->toBe(0600)
        ->and(fileperms($firstProject['journal_path']) & 0777)->toBe(0600)
        ->and(fileperms($firstProject['glossary_path']) & 0777)->toBe(0600)
        ->and(fileperms($this->journalWorkspace.'/.molly/.gitignore') & 0777)->toBe(0600)
        ->and(scandir($this->journalWorkspace.'/.molly'))->toContain('.gitignore', 'GLOSSARY.md', 'JOURNAL.md', 'journal', 'lifecycle.jsonl')
        ->and(collect(scandir($this->journalWorkspace.'/.molly'))->filter(fn ($name) => str_ends_with($name, '.tmp') || str_starts_with($name, '.graph-cache-'))->all())->toBe([])
        ->and(scandir($this->journalWorkspace.'/.molly/journal'))->toBe(['.', '..', $task->id.'.md'])
        ->and($glossaryMarkdown)->toContain('<!-- molly:glossary:start -->', '<!-- molly:glossary:end -->', 'Task:', 'Attempt:', 'Recorded pull request:', 'Recorded merge:')
        ->and($taskMarkdown)->toContain('Recorded pull request: https://github\\.com/sifrious/molly/pull/12')
        ->toContain('Lifecycle: merged https://github\\.com/sifrious/molly/pull/12 bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')
        ->not->toContain('source-secret', 'snapshot-source-secret', 'environment-secret', 'full-output-secret', 'full-source-secret', 'provider-secret', '<script', $this->journalWorkspace)
        ->and(Str::markdown($taskMarkdown))->toContain($run->id, '<blockquote>', '&lt;script&gt;')
        ->and($task->fresh()->getRawOriginal())->toBe($taskBefore)
        ->and($run->fresh()->getRawOriginal())->toBe($runBefore);

    app(NameTask::class)->handle($task->id, 'compat-renamed');
    $renamed = $exporter->handle('compat-renamed');
    $renamedProject = $exporter->forWorkspace($this->journalWorkspace);

    expect($renamed['path'])->toBe($firstTask['path'])
        ->and(glob($this->journalWorkspace.'/.molly/journal/*.md'))->toBe([$firstTask['path']])
        ->and(Str::markdown(File::get($renamed['path'])))->toContain('compat-renamed', $run->id)
        ->not->toContain('health-check')
        ->and(Str::markdown(File::get($renamedProject['journal_path'])))->toContain('compat-renamed', $run->id)
        ->not->toContain('health-check')
        ->and($run->fresh()->task_id)->toBe($task->id);
});
