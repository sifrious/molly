<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Sifrious\Molly\Actions\CheckEnvironment;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Models\Run;
use Symfony\Component\Process\Process;

it('requires a prompt for unattended execution and emits only JSON', function (): void {
    $exit = Artisan::call('molly:run', ['--json' => true, '--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
            'id' => null,
            'status' => 'failed',
            'report' => ['error' => 'Provide a prompt when using --json or --no-interaction.'],
        ]);
});

it('requires a test before starting work', function (array $options, string $message): void {
    $exit = Artisan::call('molly:run', ['prompt' => 'Fix the greeting', '--json' => true, ...$options]);

    expect($exit)->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['report']['error'])->toBe($message);
})->with([
    [[], 'Use --test to name the Pest test file that must pass.'],
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

it('requires every task history migration before declaring the database ready', function (string $filename): void {
    config(['molly.model' => '']);
    $migration = require __DIR__.'/../../database/migrations/'.$filename;
    $migration->down();
    try {
        $checks = array_column(app(CheckEnvironment::class)->handle(__DIR__.'/../..')['checks'], null, 'name');
        expect($checks['Run history']['code'])->toBe('migration_missing');
    } finally {
        $migration->up();
    }
    $checks = array_column(app(CheckEnvironment::class)->handle(__DIR__.'/../..')['checks'], null, 'name');
    expect($checks['Run history']['code'])->toBe('database_ready');
})->with([
    'snapshot' => '2026_09_17_070000_add_context_snapshot_to_molly_tasks_table.php',
    'thread associations' => '2026_09_17_080000_create_molly_task_threads_table.php',
    'journal' => '2026_09_17_090000_add_journal_status_to_molly_tasks_table.php',
    'plans' => '2026_09_17_060000_create_molly_plans_table.php',
    'protected test' => '2026_09_18_100000_add_protected_test_to_molly_tasks_table.php',
]);

it('classifies an unreachable Ollama server', function (): void {
    Http::fake(['*' => Http::failedConnection()]);

    $result = app(CheckEnvironment::class)->handle(__DIR__.'/../..');

    expect(array_column($result['checks'], 'code'))->toContain('ollama_unreachable');
    Http::assertSentCount(1);
});

it('checks actual PHP process group capabilities only for parallel execution', function (string $disabled, bool $parallel): void {
    $script = 'require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';'.<<<'PHP'
$app = Orchestra\Testbench\Foundation\Application::create(options: ['extra' => ['dont-discover' => ['*']]]);
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$app->register(Sifrious\Molly\MollyServiceProvider::class);
$kernel->registerCommand(new Sifrious\Molly\Console\MollyDoctorCommand);
Illuminate\Support\Facades\Http::preventStrayRequests();
config(['molly.parallel_checks' => $argv[1] === 'parallel', 'molly.model' => '']);
$kernel->call('molly:doctor', ['--json' => true, '--workspace' => getcwd()]);
echo $kernel->output();
PHP;
    $process = new Process([PHP_BINARY, '-d', 'disable_functions='.$disabled, '-r', $script, $parallel ? 'parallel' : 'serial'], dirname(__DIR__, 2));

    $process->mustRun();
    $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $checks = array_column($report['checks'], null, 'name');

    if ($parallel) {
        expect($report['ready'])->toBeFalse()
            ->and($checks['Parallel checks']['status'])->toBe('failed')
            ->and($checks['Parallel checks']['code'])->toBe('parallel_process_groups_unavailable')
            ->and($checks['Parallel checks']['message'])->toContain('posix_setsid', 'posix_kill', 'set molly.parallel_checks to false');
    } else {
        expect($checks)->not->toHaveKey('Parallel checks');
    }
})->with([
    'missing session support' => ['posix_setsid', true],
    'missing group signals' => ['posix_kill', true],
    'serial without POSIX' => ['posix_setsid,posix_kill', false],
]);

it('reports available process group functions for parallel execution', function (): void {
    if (! function_exists('posix_setsid') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('PHP does not provide the required POSIX functions.');
    }
    config(['molly.parallel_checks' => true, 'molly.model' => '']);
    Http::preventStrayRequests();

    $exit = Artisan::call('molly:doctor', ['--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and(array_column($report['checks'], null, 'name')['Parallel checks'])->toMatchArray([
            'status' => 'passed', 'code' => 'parallel_process_groups_ready',
        ]);
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
    $report = [
        'summary' => 'Review is still needed.',
        'mode' => 'parallel',
        'branches' => [['branch_id' => 'saved-review', 'kind' => 'review', 'status' => 'cancelled', 'failure_classification' => 'branch_cancelled']],
        'complexity_after' => ['probes' => [['metrics' => ['files' => [['path' => 'app/Greeting.php']]]]]],
    ];
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
        'review' => ['reason' => 'review_failed', 'error' => 'Ollama did not respond.'],
    ]]);

    $this->artisan('molly:show', ['run' => $run->id])
        ->expectsOutputToContain('The greeting assertion failed.')
        ->expectsOutputToContain('review_failed')
        ->expectsOutputToContain('Ollama did not respond.')
        ->assertSuccessful();
});

it('shows saved branch outcomes without changing the run status', function (string $status, ?string $failure): void {
    $run = Run::create(['prompt' => 'Fix greeting', 'workspace' => '/tmp/workspace', 'status' => 'running', 'report' => [
        'mode' => 'parallel',
        'branches' => [
            ['kind' => 'verification', 'status' => 'passed', 'execution_target' => 'local', 'provider' => null, 'model' => null, 'result_ref' => '/tmp/pest-result.json'],
            ['kind' => 'review', 'status' => $status, 'execution_target' => 'local', 'provider' => 'ollama', 'model' => 'review-model', 'failure_classification' => $failure, 'result_ref' => '/tmp/review-result.json'],
        ],
    ]]);

    $exit = Artisan::call('molly:show', ['run' => $run->id]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Execution mode: parallel', 'verification', 'Pest', 'local', 'ollama / review-model', $status, 'Run is marked running.')
        ->not->toContain('Task completed.', '/tmp/review-result.json');
    if ($failure !== null) {
        expect($output)->toContain($failure);
    }
    expect($run->fresh()->status)->toBe('running');
})->with([
    'passed review' => ['passed', null],
    'failed review' => ['failed', 'REVIEW_BLOCKED'],
    'timed out review' => ['timed_out', 'REVIEW_TIMEOUT'],
    'cancelled review' => ['cancelled', 'STOP_REQUESTED'],
]);

it('shows branch identities timing and evidence in verbose reports', function (): void {
    $report = ['mode' => 'serial', 'branches' => [[
        'branch_id' => 'review-branch-1', 'attempt_id' => 'attempt-1', 'kind' => 'review',
        'status' => 'failed', 'execution_target' => 'local', 'provider' => 'ollama', 'model' => 'review-model',
        'started_at' => '2026-09-17T10:00:00Z', 'finished_at' => '2026-09-17T10:00:30Z',
        'result_ref' => '/tmp/review-result.json', 'failure_classification' => 'REVIEW_BLOCKED',
        'reason' => 'The review found unnecessary indirection.',
    ]]];
    $run = Run::create(['prompt' => 'Fix greeting', 'workspace' => '/tmp/workspace', 'status' => 'failed', 'report' => $report]);

    $exit = Artisan::call('molly:show', ['run' => $run->id, '--verbose' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain(
            'Execution mode: serial', 'review-branch-1', 'attempt-1',
            '2026-09-17T10:00:00Z', '2026-09-17T10:00:30Z', '/tmp/review-result.json',
            'The review found unnecessary indirection.',
        );
    expect($run->fresh()->report)->toBe($report);
});

it('does not present missing branch results as passing', function (array $report, string $message): void {
    $run = Run::create(['prompt' => 'Fix greeting', 'workspace' => '/tmp/workspace', 'status' => 'running', 'report' => $report]);

    $this->artisan('molly:show', ['run' => $run->id])
        ->expectsOutputToContain($message)
        ->doesntExpectOutputToContain('passed')
        ->doesntExpectOutputToContain('Task completed.')
        ->assertSuccessful();
})->with([
    'empty branches' => [['mode' => 'parallel', 'branches' => []], 'No branch results recorded.'],
    'missing branches' => [['mode' => 'parallel'], 'No branch results recorded.'],
    'missing status' => [['mode' => 'parallel', 'branches' => [['kind' => 'review']]], 'Not reported'],
    'missing result reference' => [['mode' => 'parallel', 'branches' => [['kind' => 'review', 'status' => 'running']]], 'No result reference recorded.'],
]);

it('reports a missing nickname migration before creating tasks', function (): void {
    Http::preventStrayRequests();
    config()->set('molly.model', '');
    $migration = require __DIR__.'/../../database/migrations/2026_09_17_051234_add_nickname_to_molly_tasks_table.php';
    $migration->down();

    try {
        $exit = Artisan::call('molly:doctor', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($exit)->toBe(1)
            ->and(array_column($report['checks'], 'code'))->toContain('migration_missing');
    } finally {
        $migration->up();
    }
});

it('distinguishes missing model from unreachable Ollama in doctor messages', function (): void {
    Http::preventStrayRequests();
    Http::fake(['127.0.0.1:11434/api/tags' => Http::response(['models' => [['name' => 'other:latest']]])]);
    config([
        'molly.agent' => 'ollama',
        'molly.model' => 'starter-model',
        'ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434'],
        'molly.timeout' => 30,
    ]);

    $checks = array_column(app(CheckEnvironment::class)->handle(__DIR__.'/../..')['checks'], null, 'code');

    expect($checks['ollama_reachable']['status'])->toBe('passed')
        ->and($checks['model_missing']['status'])->toBe('failed')
        ->and($checks['model_missing']['message'])->toContain('ollama pull starter-model')
        ->and($checks['model_missing']['message'])->toContain('different from connection refused');
});

it('labels unreachable Ollama separately from config and missing-model failures', function (): void {
    Http::fake(['*' => Http::failedConnection()]);
    config([
        'molly.agent' => 'ollama',
        'molly.model' => 'starter-model',
        'ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434'],
        'molly.timeout' => 30,
    ]);

    $codes = array_column(app(CheckEnvironment::class)->handle(__DIR__.'/../..')['checks'], 'code');
    $checks = array_column(app(CheckEnvironment::class)->handle(__DIR__.'/../..')['checks'], null, 'code');

    expect($codes)->toContain('ollama_unreachable')
        ->and($codes)->not->toContain('model_missing')
        ->and($checks['ollama_unreachable']['message'])->toContain('http://127.0.0.1:11434')
        ->and($checks['ollama_unreachable']['message'])->toContain('different from a missing model');
});

it('reports the Jev gate state in doctor without ever treating an enabled but unusable gate as ready', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    config(['molly.model' => '']);
    $code = fn (): array => array_column(app(CheckEnvironment::class)->handle(__DIR__.'/../..')['checks'], null, 'name')['Jev'];

    expect($code())->toMatchArray(['status' => 'passed', 'code' => 'jev_disabled']);

    $jev = fakeJev(available: false);
    expect($code())->toMatchArray(['status' => 'failed', 'code' => 'jev_capability_missing']);

    $jev = fakeJev();
    config(['ai.providers.typesafe.key' => null]);
    expect($code())->toMatchArray(['status' => 'failed', 'code' => 'jev_unconfigured']);

    fakeJev();
    $check = $code();
    expect($check)->toMatchArray(['status' => 'passed', 'code' => 'jev_ready'])
        ->and($check['message'])->toContain('jev-latest')
        ->and($jev->requests)->toBe([]);
    Http::assertNothingSent();
});
