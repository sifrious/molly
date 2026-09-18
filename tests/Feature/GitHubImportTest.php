<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\ImportGitHubIssue;
use Sifrious\Molly\Console\MollyImportCommand;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-import-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/routes');
    File::put($this->workspace.'/routes/web.php', '<?php');
    writeProtectedTest($this->workspace, 'tests/HealthTest.php');
    Process::preventStrayProcesses();
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

function mollyIssueFixture(array $overrides = []): array
{
    return [...[
        'html_url' => 'https://github.com/sifrious/molly/issues/42',
        'number' => 42,
        'title' => 'Add a health route',
        'body' => 'Return a JSON health response.',
        'updated_at' => '2026-09-17T12:00:00Z',
        'labels' => [['name' => 'enhancement']],
    ], ...$overrides];
}

function fakeMollyIssue(string $output, int $exitCode = 0, string $errorOutput = ''): void
{
    Process::fake(["'gh' 'api' '--hostname' 'github.com' 'repos/sifrious/molly/issues/42'" => Process::result(output: $output, errorOutput: $errorOutput, exitCode: $exitCode)]);
}

function importMollyIssue(): Task
{
    return app(ImportGitHubIssue::class)->handle('https://github.com/sifrious/molly/issues/42', test()->workspace, ['routes/web.php'], 'tests/HealthTest.php');
}

it('imports issue context and provenance without starting work or writing to GitHub', function () {
    fakeMollyIssue(json_encode(mollyIssueFixture()));

    $task = importMollyIssue()->fresh();

    expect($task->status)->toBe('pending')
        ->and($task->source)->toBe([
            'repository' => 'sifrious/molly', 'issue_number' => 42,
            'issue_url' => 'https://github.com/sifrious/molly/issues/42',
            'issue_title' => 'Add a health route', 'issue_updated_at' => '2026-09-17T12:00:00Z',
            'labels' => ['enhancement'], 'linked_pr' => null,
        ])
        ->and($task->prompt)->toContain('Add a health route', 'Return a JSON health response.', 'https://github.com/sifrious/molly/issues/42')
        ->and($task->paths)->toBe(['routes/web.php'])
        ->and(Run::count())->toBe(0)
        ->and($task->allow_test_edits)->toBeFalse();
    Process::assertRanTimes(fn (PendingProcess $process): bool => $process->command === ['gh', 'api', '--hostname', 'github.com', 'repos/sifrious/molly/issues/42'] && $process->timeout === 15, 1);
});

it('accepts an issue without a body', function () {
    fakeMollyIssue(json_encode(mollyIssueFixture(['body' => null, 'labels' => []])));

    expect(importMollyIssue()->prompt)->toContain('Add a health route');
});

it('rejects malicious or unsupported URLs before invoking gh', function (string $url) {
    Process::fake();

    expect(fn () => app(ImportGitHubIssue::class)->handle($url, $this->workspace, ['tests/HealthTest.php'], 'tests/HealthTest.php'))
        ->toThrow(RuntimeException::class, 'ISSUE_URL_INVALID');
    expect(Task::count())->toBe(0);
    Process::assertNothingRan();
})->with([
    'http://github.com/sifrious/molly/issues/42',
    'https://github.com.evil.test/sifrious/molly/issues/42',
    'https://token@github.com/sifrious/molly/issues/42',
    'https://github.com/sifrious/molly/pull/42',
    'https://github.com/sifrious/molly/issues/42?x=1',
    'https://github.com/sifrious/molly/issues/42#comment',
    'https://github.com/sifrious/molly/issues/0',
    'https://github.com/sifrious/molly/issues/999999999999999999999999',
    'https://github.com/sifrious/../issues/42',
    'https://github.com/sifrious/molly/issues/42;touch /tmp/unexpected',
]);

it('rejects invalid or mismatched issue payloads before persisting a task', function (array $overrides) {
    fakeMollyIssue(json_encode(mollyIssueFixture($overrides)));

    expect(fn () => importMollyIssue())->toThrow(RuntimeException::class, 'ISSUE_INVALID');
    expect(Task::count())->toBe(0);
})->with([
    'pull request' => [['pull_request' => ['url' => 'https://api.github.com/pulls/42']]],
    'wrong issue number' => [['number' => 43]],
    'wrong source URL' => [['html_url' => 'https://github.com/other/project/issues/42']],
    'invalid title' => [['title' => []]],
    'invalid body' => [['body' => ['unexpected']]],
    'invalid updated time' => [['updated_at' => 'yesterday']],
    'invalid labels' => [['labels' => 'bug']],
    'invalid label name' => [['labels' => [['name' => 42]]]],
]);

it('rejects malformed JSON without persisting a task', function () {
    fakeMollyIssue('not JSON');

    expect(fn () => importMollyIssue())->toThrow(RuntimeException::class, 'ISSUE_INVALID');
    expect(Task::count())->toBe(0);
});

it('does not truncate an issue that exceeds the task prompt limit', function () {
    fakeMollyIssue(json_encode(mollyIssueFixture(['body' => str_repeat('a', 8192)])));

    expect(fn () => importMollyIssue())->toThrow(RuntimeException::class, 'ISSUE_TOO_LARGE');
    expect(Task::count())->toBe(0);
});

it('retains task scope validation during import', function () {
    fakeMollyIssue(json_encode(mollyIssueFixture()));

    expect(fn () => app(ImportGitHubIssue::class)->handle('https://github.com/sifrious/molly/issues/42', $this->workspace, ['tests/../HealthTest.php'], 'tests/../HealthTest.php'))
        ->toThrow(RuntimeException::class, 'PATH_INVALID');
    expect(Task::count())->toBe(0);
});

it('returns JSON provenance from the import command', function () {
    Artisan::registerCommand(app(MollyImportCommand::class));
    fakeMollyIssue(json_encode(mollyIssueFixture()));

    $exit = Artisan::call('molly:import', ['issue' => 'https://github.com/sifrious/molly/issues/42', '--workspace' => $this->workspace, '--file' => ['routes/web.php'], '--test' => 'tests/HealthTest.php', '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($output['status'])->toBe('pending')
        ->and($output['task']['source']['issue_number'])->toBe(42)
        ->and(Task::find($output['id']))->not->toBeNull();
});

it('classifies GitHub failures without exposing command stderr', function () {
    Artisan::registerCommand(app(MollyImportCommand::class));
    fakeMollyIssue('', 1, 'token secret-value private failure');

    $exit = Artisan::call('molly:import', ['issue' => 'https://github.com/sifrious/molly/issues/42', '--workspace' => $this->workspace, '--file' => ['routes/web.php'], '--test' => 'tests/HealthTest.php', '--json' => true]);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($output['error'])->toStartWith('GITHUB_UNAVAILABLE:')
        ->and(Artisan::output())->not->toContain('secret-value')
        ->and(Task::count())->toBe(0);
});

it('classifies process exceptions without exposing exception details', function () {
    Process::fake(["'gh' 'api' '--hostname' 'github.com' 'repos/sifrious/molly/issues/42'" => function () {
        throw new RuntimeException('secret process details');
    }]);

    expect(fn () => importMollyIssue())->toThrow(RuntimeException::class, 'GITHUB_UNAVAILABLE: Molly could not read the issue. Check gh installation, login, and network access.');
    expect(Task::count())->toBe(0);
});
