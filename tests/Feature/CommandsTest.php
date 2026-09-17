<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Sifrious\Molly\Actions\CheckEnvironment;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Models\Run;

it('requires a prompt for unattended execution and emits only JSON', function (): void {
    $exit = Artisan::call('molly:run', ['--json' => true, '--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'id' => null,
            'status' => 'failed',
            'report' => ['error' => 'Provide a prompt when using --json or --no-interaction.'],
        ]);
});

it('requires explicit files and a test before starting work', function (array $options, string $message): void {
    $exit = Artisan::call('molly:run', ['prompt' => 'Fix the greeting', '--json' => true, ...$options]);

    expect($exit)->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['report']['error'])->toBe($message);
})->with([
    [[], 'Use --file for each file Molly may change.'],
    [['--file' => ['app/Greeting.php']], 'Use --test to name the Pest test file that must pass.'],
]);

it('returns the complete run report with the correct exit code', function (string $status, int $exit): void {
    $run = new Run;
    $run->forceFill(['id' => 'run-1', 'status' => $status, 'report' => ['summary' => 'Updated the greeting.', 'review' => ['findings' => []]]]);
    $action = Mockery::mock(RunTask::class);
    $action->shouldReceive('handle')->once()->with('Fix the greeting', '/tmp/molly-workspace', ['app/Greeting.php'], 'tests/Feature/GreetingTest.php', null)->andReturn($run);
    $this->app->instance(RunTask::class, $action);

    $actual = Artisan::call('molly:run', ['prompt' => 'Fix the greeting', '--workspace' => '/tmp/molly-workspace', '--file' => ['app/Greeting.php'], '--test' => 'tests/Feature/GreetingTest.php', '--json' => true]);

    expect($actual)->toBe($exit)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe(['id' => $run->id, 'status' => $status, 'report' => $run->report]);
})->with([['completed', 0], ['failed', 1], ['running', 1]]);

it('returns JSON when task execution throws', function (): void {
    $action = Mockery::mock(RunTask::class);
    $action->shouldReceive('handle')->once()->andThrow(new RuntimeException('Ollama did not respond.'));
    $this->app->instance(RunTask::class, $action);

    $exit = Artisan::call('molly:run', ['prompt' => 'Fix the greeting', '--file' => ['app/Greeting.php'], '--test' => 'tests/Feature/GreetingTest.php', '--json' => true]);

    expect($exit)->toBe(1)->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['report']['error'])->toBe('Ollama did not respond.');
});

it('prints doctor checks as JSON and fails when requirements are missing', function (): void {
    Http::preventStrayRequests();
    Http::fake(['localhost:11434/api/tags' => Http::response(['models' => [['name' => 'different-model']]])]);
    config()->set('molly.model', 'required-model');
    Schema::rename('molly_runs', 'molly_runs_unavailable');

    try {
        $exit = Artisan::call('molly:doctor', ['--workspace' => '/missing-workspace', '--json' => true]);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    } finally {
        Schema::rename('molly_runs_unavailable', 'molly_runs');
    }

    expect($exit)->toBe(1)->and($result['ready'])->toBeFalse()
        ->and(array_column($result['checks'], 'code'))->toContain('migration_missing', 'pest_missing', 'model_missing', 'clever_ready');
    Http::assertSentCount(1);
});

it('does not contact a nonlocal Ollama endpoint', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    config()->set('ai.providers.ollama.url', 'https://example.com');
    config()->set('molly.model', '');

    $result = app(CheckEnvironment::class)->handle(__DIR__.'/../..');

    expect(array_column($result['checks'], 'code'))->toContain('model_not_configured');
    Http::assertNothingSent();
});

it('classifies an unreachable Ollama server', function (): void {
    Http::fake(['*' => Http::failedConnection()]);

    $result = app(CheckEnvironment::class)->handle(__DIR__.'/../..');

    expect(array_column($result['checks'], 'code'))->toContain('ollama_unreachable');
    Http::assertSentCount(1);
});

it('shows tests and complexity findings beside changed files', function (): void {
    $run = new Run;
    $run->forceFill(['id' => 'run-readable', 'status' => 'failed', 'report' => [
        'summary' => 'The greeting changed, but review found unnecessary indirection.',
        'changes' => [['path' => 'app/Greeting.php', 'status' => 'modified']],
        'verification' => ['status' => 'passed', 'tests' => 1, 'assertions' => 2, 'output' => 'RAW_PEST_JSON'],
        'review' => ['checks' => ['A' => ['status' => 'findings', 'evidence' => 'One wrapper only forwards a call.']], 'findings' => [
            ['severity' => 'blocking', 'classification' => 'accidental', 'path' => 'app/Greeting.php', 'line' => 12, 'problem' => 'The wrapper adds no behavior.', 'recommendation' => 'Call the existing method directly.'],
        ]],
        'complexity_after' => ['status' => 'ok', 'probes' => [['key' => 'c1', 'name' => 'Owned diff', 'status' => 'ok', 'headline' => '2 owned lines changed.', 'metrics' => ['owned_lines' => 2]]]],
    ]]);
    $action = Mockery::mock(RunTask::class);
    $action->shouldReceive('handle')->once()->andReturn($run);
    $this->app->instance(RunTask::class, $action);

    $this->artisan('molly:run', ['prompt' => 'Fix the greeting', '--file' => ['app/Greeting.php'], '--test' => 'tests/Feature/GreetingTest.php', '--no-interaction' => true])
        ->expectsOutputToContain('The wrapper adds no behavior.')
        ->expectsOutputToContain('app/Greeting.php:12')
        ->expectsOutputToContain('php artisan clever:owned-diff')
        ->expectsOutputToContain('1 test passed, 2 assertions.')
        ->doesntExpectOutputToContain('RAW_PEST_JSON')
        ->assertFailed();
});

it('asks for a missing prompt before running an interactive task', function (): void {
    $run = new Run;
    $run->forceFill(['id' => 'interactive-run', 'status' => 'completed', 'report' => []]);
    $action = Mockery::mock(RunTask::class);
    $action->shouldReceive('handle')->once()->with('Fix the greeting', Mockery::type('string'), ['app/Greeting.php'], 'tests/Feature/GreetingTest.php', Mockery::type(Closure::class))->andReturn($run);
    $this->app->instance(RunTask::class, $action);

    $this->artisan('molly:run', ['--file' => ['app/Greeting.php'], '--test' => 'tests/Feature/GreetingTest.php'])
        ->expectsQuestion('What should Molly work on?', 'Fix the greeting')
        ->assertSuccessful();
});

it('reads saved JSON reports without executing a new task', function (): void {
    $report = ['summary' => 'Review is still needed.', 'complexity_after' => ['probes' => [['metrics' => ['files' => [['path' => 'app/Greeting.php']]]]]]];
    $run = Run::create(['prompt' => 'Fix greeting', 'workspace' => '/tmp/another-checkout', 'status' => 'failed', 'report' => $report]);
    $action = Mockery::mock(RunTask::class);
    $action->shouldNotReceive('handle');
    $this->app->instance(RunTask::class, $action);

    $exit = Artisan::call('molly:show', ['run' => $run->id, '--json' => true]);

    expect($exit)->toBe(0)->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe(['id' => $run->id, 'status' => 'failed', 'report' => $report]);
});

it('returns a JSON error for an unknown saved run', function (): void {
    $exit = Artisan::call('molly:show', ['run' => 'unknown', '--json' => true]);

    expect($exit)->toBe(1)->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'id' => 'unknown', 'status' => 'error', 'report' => ['error' => 'RUN_NOT_FOUND: No saved run has that ID.'],
    ]);
});

it('shows probe limitations and the workspace without dumping file arrays', function (): void {
    $run = Run::create(['prompt' => 'Fix greeting', 'workspace' => '/tmp/another-checkout', 'status' => 'running', 'report' => [
        'complexity_before' => ['status' => 'skipped', 'report' => '/tmp/evidence/clever.json', 'probes' => [[
            'name' => 'Lonely files', 'key' => 'c3', 'status' => 'skipped', 'headline' => 'No author history.',
            'skip_reason' => 'The checkout has no Git commits.', 'hand_verify' => 'Run git shortlog -s in the selected workspace.',
            'metrics' => ['count' => 0, 'files' => [['path' => 'NESTED_DETAILS_NOT_FOR_TABLE']]],
        ]]],
    ]]);

    $this->artisan('molly:show', ['run' => $run->id, '--verbose' => true])
        ->expectsOutputToContain('Workspace: /tmp/another-checkout')
        ->expectsOutputToContain('The checkout has no Git commits.')
        ->expectsOutputToContain('Run git shortlog -s in the selected workspace.')
        ->expectsOutputToContain('Standalone Clever commands use the host application root or molly-complexity.root.')
        ->expectsOutputToContain('/tmp/evidence/clever.json')
        ->expectsOutputToContain('An interrupted run may still have this status.')
        ->doesntExpectOutputToContain('NESTED_DETAILS_NOT_FOR_TABLE')
        ->assertSuccessful();
});

it('compares compact measurements and keeps full caveats behind verbose output', function (bool $verbose): void {
    $probe = [
        'name' => 'Owned diff', 'key' => 'c1', 'status' => 'ok', 'headline' => 'Probe headline.',
        'metrics' => ['owned_lines' => 12, 'has_baseline' => true, 'files' => [['path' => 'NESTED_ROW']]],
        'hand_verify' => 'Inspect the ownership baseline.', 'caveats' => ['LONG_SOURCE_CAVEAT'],
    ];
    $after = [...$probe, 'status' => 'skipped', 'skip_reason' => 'History is unavailable.', 'metrics' => ['owned_lines' => 8, 'has_baseline' => false]];
    $run = Run::create(['prompt' => 'Fix greeting', 'workspace' => '/tmp/workspace', 'status' => 'completed', 'report' => [
        'verification' => ['status' => 'passed', 'tests' => 2, 'assertions' => 3, 'output' => 'RAW_TEST_DETAILS'],
        'review' => ['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'Review evidence remains visible.'])],
        'complexity_before' => ['status' => 'ok', 'probes' => [$probe], 'report' => '/tmp/before.json'],
        'complexity_after' => ['status' => 'skipped', 'probes' => [$after], 'report' => '/tmp/after.json'],
    ]]);

    $command = $this->artisan('molly:show', ['run' => $run->id, ...($verbose ? ['--verbose' => true] : [])])
        ->expectsOutputToContain('2 tests passed, 3 assertions.')
        ->expectsOutputToContain($verbose ? 'has baseline' : 'owned lines')
        ->expectsOutputToContain('After skipped: History is unavailable.')
        ->expectsOutputToContain('/tmp/before.json')
        ->expectsOutputToContain('/tmp/after.json')
        ->expectsOutputToContain('Tarpit G / clean')
        ->doesntExpectOutputToContain('NESTED_ROW');
    if ($verbose) {
        $command->expectsOutputToContain('LONG_SOURCE_CAVEAT')
            ->expectsOutputToContain('Inspect the ownership baseline.')
            ->expectsOutputToContain('RAW_TEST_DETAILS');
    } else {
        $command->doesntExpectOutputToContain('LONG_SOURCE_CAVEAT')
            ->doesntExpectOutputToContain('Inspect the ownership baseline.')
            ->doesntExpectOutputToContain('RAW_TEST_DETAILS');
    }
    $command->assertSuccessful();
})->with([false, true]);

it('keeps failed test output visible without verbose mode', function (): void {
    $run = Run::create(['prompt' => 'Fix greeting', 'workspace' => '/tmp/workspace', 'status' => 'failed', 'report' => [
        'verification' => ['status' => 'failed', 'tests' => 1, 'output' => 'The greeting assertion failed.'],
    ]]);

    $this->artisan('molly:show', ['run' => $run->id])
        ->expectsOutputToContain('The greeting assertion failed.')
        ->assertSuccessful();
});
