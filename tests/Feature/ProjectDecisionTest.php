<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\RecordProjectDecision;
use Sifrious\Molly\Models\Task;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-decision-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace);
    $this->travelTo('2026-09-19 12:00:00');
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('writes a Git-tracked decision without starting a task', function () {
    $result = app(RecordProjectDecision::class)->handle(
        $this->workspace,
        'Protect Pest tests',
        'The implementation writer cannot edit the locked acceptance test.',
        'health-check',
    );

    $path = (realpath($this->workspace) ?: $this->workspace).'/docs/decisions/2026-09-19-protect-pest-tests.md';
    expect($result)->toBe(['path' => $path, 'title' => 'Protect Pest tests', 'created' => true])
        ->and(File::get($path))->toContain('# Protect Pest tests', 'Date: 2026-09-19', 'Status: accepted', 'Task: health-check', 'The implementation writer cannot edit the locked acceptance test.')
        ->and(File::get($path))->not->toContain('.molly/');
});

it('is idempotent for the same title and body on the same day', function () {
    $action = app(RecordProjectDecision::class);
    $first = $action->handle($this->workspace, 'Keep Pest required', 'Pest remains the hard completion gate.');
    $again = $action->handle($this->workspace, 'Keep Pest required', 'Pest remains the hard completion gate.');

    expect($again['created'])->toBeFalse()
        ->and($again['path'])->toBe($first['path'])
        ->and(File::get($first['path']))->toBe(File::get($again['path']));
});

it('refuses a different decision that reuses today\'s title', function () {
    $action = app(RecordProjectDecision::class);
    $action->handle($this->workspace, 'Keep Pest required', 'Pest remains the hard completion gate.');

    expect(fn () => $action->handle($this->workspace, 'Keep Pest required', 'A later note tries to replace the first decision.'))
        ->toThrow(RuntimeException::class, 'DECISION_EXISTS');
});

it('records a decision through Artisan without creating a Molly task', function () {
    expect(Artisan::call('molly:decide', [
        '--workspace' => $this->workspace,
        '--title' => 'Keep Pest required',
        '--body' => 'Pest remains the hard completion gate.',
        '--json' => true,
    ]))->toBe(0);

    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['created'])->toBeTrue()
        ->and($result['path'])->toEndWith('/docs/decisions/2026-09-19-keep-pest-required.md')
        ->and(Task::count())->toBe(0);
});
