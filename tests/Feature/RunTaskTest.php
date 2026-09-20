<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\TarpitReviewer;
use Sifrious\Molly\Models\Run;

function mollyReviewFixture(): array
{
    $checks = [];

    foreach (range('A', 'G') as $check) {
        $checks[$check] = ['status' => 'clean', 'evidence' => 'No finding in the selected files.'];
    }

    return ['checks' => $checks, 'findings' => []];
}

function mollyProposalFixture(): array
{
    return [
        'summary' => 'Return a greeting.',
        'files' => [
            ['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";'],
        ],
    ];
}

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-task-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($this->workspace);
    config(['ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434']]);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

function measureMollyFixture(): void
{
    test()->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn([
        'status' => 'skipped',
        'probes' => [['key' => 'c3', 'status' => 'skipped', 'skip_reason' => 'no_commits']],
    ]);
}

function runMollyFixture(): Run
{
    return app(RunTask::class)->handle('Return Hello.', test()->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
}

it('saves the active phase and completed measurements before requesting model changes', function () {
    measureMollyFixture();
    $previous = ['run_id' => 'earlier-run', 'status' => 'failed', 'verification' => ['output' => 'Missing import.']];
    $this->mock(GenerateChanges::class)->shouldReceive('handle')->once()
        ->withArgs(function (string $prompt, array $files, string $test, array $evidence) use ($previous): bool {
            $saved = Run::firstOrFail();
            expect($saved->report['phase'])->toBe('Writing the selected files with Ollama')
                ->and($saved->report['complexity_before']['status'])->toBe('skipped')
                ->and($evidence)->toBe($previous);

            return $prompt === 'Return Hello.' && $test === 'tests/GreetingTest.php';
        })->andReturn(mollyProposalFixture());
    TarpitReviewer::fake([mollyReviewFixture()])->preventStrayPrompts();
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true]);

    $run = app(RunTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php', previousAttempt: $previous);

    expect($run->status)->toBe('completed');
});

it('persists completion only after tests and the complete review pass', function () {
    ChangeWriter::fake([mollyProposalFixture()])->preventStrayPrompts();
    TarpitReviewer::fake([mollyReviewFixture()])->preventStrayPrompts();
    measureMollyFixture();
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true]);

    $run = runMollyFixture();

    expect($run->status)->toBe('completed')
        ->and(Run::findOrFail($run->id)->status)->toBe('completed')
        ->and($run->report['verification']['tests'])->toBe(1)
        ->and($run->report['changes'])->toHaveCount(1)
        ->and($run->report['changes'][0]['status'])->toBe('modified')
        ->and($run->report['complexity_after']['probes'][0]['status'])->toBe('skipped')
        ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe('<?php return "Hello";');
    ChangeWriter::assertPromptedTimes(1);
    TarpitReviewer::assertPromptedTimes(1);
});

it('keeps a run failed when required tests fail despite a clean review', function () {
    ChangeWriter::fake([mollyProposalFixture()])->preventStrayPrompts();
    TarpitReviewer::fake([mollyReviewFixture()])->preventStrayPrompts();
    measureMollyFixture();
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'failed', 'tests' => 1, 'failures' => 1]);

    $run = runMollyFixture();

    expect($run->status)->toBe('failed')
        ->and($run->report['verification']['failures'])->toBe(1)
        ->and($run->report['review']['checks'])->toHaveCount(7);
});

it('keeps blocking complexity findings visible even when tests pass', function () {
    $review = mollyReviewFixture();
    $review['checks']['E'] = ['status' => 'findings', 'evidence' => 'The selected file contains an unnecessary indirection.'];
    $review['findings'][] = ['code' => 'E', 'classification' => 'accidental', 'severity' => 'blocking', 'path' => 'app/Greeting.php', 'line' => 1, 'problem' => 'One extra call has no purpose.', 'recommendation' => 'Return the greeting directly.'];
    ChangeWriter::fake([mollyProposalFixture()])->preventStrayPrompts();
    TarpitReviewer::fake([$review])->preventStrayPrompts();
    measureMollyFixture();
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1]);

    $run = runMollyFixture();

    expect($run->status)->toBe('failed')
        ->and($run->report['verification']['status'])->toBe('passed')
        ->and($run->report['review']['findings'][0]['severity'])->toBe('blocking');
});

it('records incomplete reviews as failures and retains the observed test results', function () {
    ChangeWriter::fake([mollyProposalFixture()])->preventStrayPrompts();
    TarpitReviewer::fake([['checks' => [], 'findings' => []]])->preventStrayPrompts();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->once()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1]);

    $run = runMollyFixture();

    expect($run->status)->toBe('failed')
        ->and($run->report['error'])->toStartWith('REVIEW_INVALID:')
        ->and($run->report['verification']['tests'])->toBe(1)
        ->and($run->report['changes'])->toHaveCount(1);
});

it('does not edit files or call the model when Clever is unavailable', function () {
    ChangeWriter::fake()->preventStrayPrompts();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->once()->andReturn(['status' => 'unavailable', 'reason' => 'clever_not_installed', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldNotReceive('handle');

    $run = runMollyFixture();

    expect($run->status)->toBe('failed')
        ->and($run->report['error'])->toStartWith('CLEVER_UNAVAILABLE:')
        ->and($run->report['changes'])->toBe([])
        ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe('<?php return null;');
    ChangeWriter::assertNeverPrompted();
});

it('rejects a proposal outside the selected paths without writing any files', function () {
    $proposal = mollyProposalFixture();
    $proposal['files'][] = ['path' => '.env', 'content' => 'changed'];
    ChangeWriter::fake([$proposal])->preventStrayPrompts();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->once()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldNotReceive('handle');

    $run = runMollyFixture();

    expect($run->status)->toBe('failed')
        ->and($run->report['error'])->toStartWith('GENERATION_INVALID:')
        ->and($run->report['changes'])->toBe([])
        ->and(File::exists($this->workspace.'/.env'))->toBeFalse();
});

it('does not report unchanged proposals as completed work', function () {
    ChangeWriter::fake([['summary' => 'No change.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return null;']]]])->preventStrayPrompts();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->once()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldNotReceive('handle');

    $run = runMollyFixture();

    expect($run->status)->toBe('failed')
        ->and($run->report['error'])->toStartWith('NO_CHANGES:');
});

it('invalidates results when a file changes during verification', function () {
    ChangeWriter::fake([mollyProposalFixture()])->preventStrayPrompts();
    TarpitReviewer::fake([mollyReviewFixture()])->preventStrayPrompts();
    measureMollyFixture();
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturnUsing(function () {
        File::put($this->workspace.'/app/Greeting.php', '<?php return "Changed outside Molly";');

        return ['status' => 'passed', 'tests' => 1];
    });

    $run = runMollyFixture();

    expect($run->status)->toBe('failed')
        ->and($run->report['error'])->toStartWith('WORKSPACE_CHANGED:')
        ->and($run->report['changes'][0]['after_hash'])->toBe(hash('sha256', '<?php return "Changed outside Molly";'));
});

it('rejects invalid task input before creating a run', function (string $prompt, string $testPath) {
    expect(fn () => app(RunTask::class)->handle($prompt, $this->workspace, ['app/Greeting.php'], $testPath))
        ->toThrow(RuntimeException::class);
    expect(Run::count())->toBe(0);
})->with([
    'empty prompt' => ['', 'tests/GreetingTest.php'],
    'missing test' => ['Return Hello.', ''],
    'application file as test' => ['Return Hello.', 'app/Greeting.php'],
]);

it('finalizes NOT_RUN receipts when a run stops before verification', function () {
    config(['molly.parallel_checks' => false]);

    $run = app(RunTask::class)->handle(
        'Return Hello.',
        $this->workspace,
        ['app/Greeting.php'],
        'tests/GreetingTest.php',
        shouldStop: fn (): bool => true,
    );

    expect($run->status)->toBe('stopped')
        ->and($run->report['terminated_before_completion'] ?? null)->toBeTrue()
        ->and($run->report['verification_outcomes']['pest']['state'])->toBe('NOT_RUN')
        ->and($run->report['verification_outcomes']['tarpit']['state'])->toBe('NOT_RUN')
        ->and($run->report['verification_outcomes']['pest']['policy'])->toBe('required')
        ->and($run->report['verification_outcomes']['tarpit']['policy'])->toBe('required');

    $pestReceipt = $this->workspace.'/.molly/receipts/'.$run->id.'/pest.json';
    $tarpitReceipt = $this->workspace.'/.molly/receipts/'.$run->id.'/tarpit.json';
    expect(is_file($pestReceipt))->toBeTrue()
        ->and(is_file($tarpitReceipt))->toBeTrue()
        ->and(File::get($pestReceipt))->toContain('"state":"NOT_RUN"')
        ->and(File::get($tarpitReceipt))->toContain('"state":"NOT_RUN"');
});
