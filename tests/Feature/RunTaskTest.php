<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Actions\DecideRunCompletion;
use Sifrious\Molly\Actions\EvaluateChanges;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RecordLifecycleEvent;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\TarpitReviewer;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Verification\FalseGreenVerifier;

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

it('proves RunTask phase order and failure semantics', function () {
    $phases = [];
    $progress = function (string $message) use (&$phases): void {
        $phases[] = $message;
    };

    // Serial success: measurements → generate → verify → review → after measure → receipts → advisory classification → snapshots.
    config(['molly.parallel_checks' => false, 'molly.false_green.enabled' => false]);
    $task = app(CreateTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    ChangeWriter::fake([mollyProposalFixture()])->preventStrayPrompts();
    TarpitReviewer::fake([mollyReviewFixture()])->preventStrayPrompts();
    measureMollyFixture();
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn([
        'status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true,
    ]);

    $serial = app(RunTask::class)->handle(
        'Return Hello.',
        $this->workspace,
        ['app/Greeting.php'],
        'tests/GreetingTest.php',
        progress: $progress,
        taskId: $task->id,
    );

    expect($serial->status)->toBe('completed')
        ->and($serial->report['mode'])->toBe('serial')
        ->and($serial->report['classification']['advisory'])->toBeTrue()
        ->and($serial->report['verification_receipts'])->not->toBeEmpty()
        ->and($serial->report['snapshots']['before']['status'])->toBe('captured')
        ->and($serial->report['snapshots']['after']['status'])->toBe('captured')
        ->and($serial->report['components']['status'])->toBe('compared')
        ->and($phases)->toContain(
            'Measuring complexity before changes',
            'Writing the selected files with Ollama',
            'Applying the proposed changes',
            'Running the required Pest tests',
            'Reviewing complexity with the seven Tarpit checks',
            'Measuring complexity after changes',
        );

    $log = app(RecordLifecycleEvent::class)->load($this->workspace);
    $types = array_map(fn ($event) => $event->type()?->value, $log->events($task->id));
    expect($types)->toContain(
        LifecycleEventType::DispatchRequested->value,
        LifecycleEventType::AgentStarted->value,
        LifecycleEventType::ProposalReceived->value,
        LifecycleEventType::EditsAccepted->value,
        LifecycleEventType::VerificationStarted->value,
    );

    app()->forgetInstance(RunTask::class);
    // Parallel mode uses EvaluateChanges and records mode=parallel.
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    $phases = [];
    config(['molly.parallel_checks' => true]);
    ChangeWriter::fake([mollyProposalFixture()])->preventStrayPrompts();
    measureMollyFixture();
    $this->mock(VerifyChanges::class)->shouldNotReceive('handle');
    $this->mock(EvaluateChanges::class)->shouldReceive('handle')->once()->andReturn([
        'verification' => ['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true],
        'review' => mollyReviewFixture(),
        'branches' => [
            [
                'kind' => 'verification', 'branch_id' => 'verification-1', 'attempt_id' => 'attempt-1',
                'execution_target' => 'local', 'status' => 'passed', 'finished_at' => '2026-09-22T12:00:00Z',
                'result_ref' => '/evidence/verification.json',
            ],
            [
                'kind' => 'review', 'branch_id' => 'review-1', 'attempt_id' => 'attempt-1',
                'execution_target' => 'local', 'status' => 'passed', 'finished_at' => '2026-09-22T12:00:01Z',
                'result_ref' => '/evidence/review.json',
            ],
        ],
    ]);

    $parallel = app(RunTask::class)->handle(
        'Return Hello.',
        $this->workspace,
        ['app/Greeting.php'],
        'tests/GreetingTest.php',
        progress: $progress,
    );

    expect($parallel->status)->toBe('completed')
        ->and($parallel->report['mode'])->toBe('parallel')
        ->and($phases)->toContain('Running Pest and Tarpit review in parallel');

    app()->forgetInstance(RunTask::class);
    // Protected test integrity before apply.
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    config(['molly.parallel_checks' => false]);
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->once()->andReturn(['status' => 'ok', 'probes' => []]);
    ChangeWriter::fake([[
        'summary' => 'Weaken the test.',
        'files' => [['path' => 'tests/GreetingTest.php', 'content' => '<?php it("weak", fn () => expect(true)->toBeTrue());']],
    ]])->preventStrayPrompts();
    $this->mock(VerifyChanges::class)->shouldNotReceive('handle');

    $protected = app(RunTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    expect($protected->status)->toBe('failed')
        ->and($protected->report['error'])->toStartWith('PROTECTED_TEST_CHANGED:')
        ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe('<?php return null;');

    app()->forgetInstance(RunTask::class);
    // No-changes rejection after proposal.
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->once()->andReturn(['status' => 'ok', 'probes' => []]);
    ChangeWriter::fake([['summary' => 'No change.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return null;']]]])->preventStrayPrompts();
    $this->mock(VerifyChanges::class)->shouldNotReceive('handle');
    $noChanges = app(RunTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    expect($noChanges->status)->toBe('failed')
        ->and($noChanges->report['error'])->toStartWith('NO_CHANGES:');

    app()->forgetInstance(RunTask::class);
    // Workspace mutation after verification invalidates the run.
    ChangeWriter::fake([mollyProposalFixture()])->preventStrayPrompts();
    TarpitReviewer::fake([mollyReviewFixture()])->preventStrayPrompts();
    measureMollyFixture();
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturnUsing(function () {
        File::put($this->workspace.'/app/Greeting.php', '<?php return "mutated";');

        return ['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true];
    });
    $mutated = app(RunTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    expect($mutated->status)->toBe('failed')
        ->and($mutated->report['error'])->toStartWith('WORKSPACE_CHANGED:');

    app()->forgetInstance(RunTask::class);
    // False-green probe runs when enabled and keeps advisory classification on success.
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    config(['molly.false_green.enabled' => true]);
    ChangeWriter::fake([mollyProposalFixture()])->preventStrayPrompts();
    TarpitReviewer::fake([mollyReviewFixture()])->preventStrayPrompts();
    measureMollyFixture();
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn([
        'status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true,
    ]);
    $this->mock(FalseGreenVerifier::class)->shouldReceive('handle')->once()->andReturn([
        'status' => 'clean', 'state' => 'PASS', 'conclusion' => 'strong_test',
    ]);
    $falseGreen = app(RunTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    expect($falseGreen->status)->toBe('completed')
        ->and($falseGreen->report['false_green']['status'])->toBe('clean')
        ->and($falseGreen->report['classification']['advisory'])->toBeTrue();
    config(['molly.false_green.enabled' => false]);

    app()->forgetInstance(RunTask::class);
    // Stop boundary before generation finalizes NOT_RUN receipts and stopped status.
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->never();
    $stopped = app(RunTask::class)->handle(
        'Return Hello.',
        $this->workspace,
        ['app/Greeting.php'],
        'tests/GreetingTest.php',
        shouldStop: fn (): bool => true,
    );
    expect($stopped->status)->toBe('stopped')
        ->and($stopped->report['terminated_before_completion'])->toBeTrue()
        ->and($stopped->report['verification_outcomes']['pest']['state'])->toBe('NOT_RUN')
        ->and($stopped->report['verification_outcomes']['tarpit']['state'])->toBe('NOT_RUN')
        ->and(is_file($this->workspace.'/.molly/receipts/'.$stopped->id.'/pest.json'))->toBeTrue();

    app()->forgetInstance(RunTask::class);
    // Measurement failure blocks generation.
    ChangeWriter::fake()->preventStrayPrompts();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->once()->andReturn([
        'status' => 'unavailable', 'reason' => 'clever_not_installed', 'probes' => [],
    ]);
    $this->mock(VerifyChanges::class)->shouldNotReceive('handle');
    $measureFail = app(RunTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    expect($measureFail->status)->toBe('failed')
        ->and($measureFail->report['error'])->toStartWith('CLEVER_UNAVAILABLE:')
        ->and($measureFail->report['changes'])->toBe([])
        ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe('<?php return "Hello";');

    app()->forgetInstance(RunTask::class);
    // Snapshot capture failure on terminated path marks components unavailable.
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    ChangeWriter::fake([mollyProposalFixture()])->preventStrayPrompts();
    TarpitReviewer::fake([mollyReviewFixture()])->preventStrayPrompts();
    measureMollyFixture();
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturnUsing(function () {
        File::delete($this->workspace.'/app/Greeting.php');
        File::makeDirectory($this->workspace.'/app/Greeting.php');

        return ['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true];
    });
    $snapshotFail = app(RunTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    expect($snapshotFail->status)->toBe('failed')
        ->and($snapshotFail->report['changes_unavailable'] ?? null)->toBeTrue()
        ->and($snapshotFail->report['snapshots']['after']['status'])->toBe('unavailable')
        ->and($snapshotFail->report['snapshots']['after']['reason'])->toStartWith('SNAPSHOT_CAPTURE_FAILED:')
        ->and($snapshotFail->report['components']['status'])->toBe('unavailable');

    app()->forgetInstance(RunTask::class);
    // Receipt/finalization failure on stop still persists stopped status with receipt_error.
    $greeting = $this->workspace.'/app/Greeting.php';
    if (is_dir($greeting)) {
        rmdir($greeting);
    }
    File::put($greeting, '<?php return null;');
    writeProtectedTest($this->workspace);
    $this->mock(DecideRunCompletion::class)->shouldReceive('forTerminated')->twice()
        ->andThrow(new RuntimeException('RECEIPT_FINALIZE_FAILED: outcomes unavailable'));
    $receiptFail = app(RunTask::class)->handle(
        'Return Hello.',
        $this->workspace,
        ['app/Greeting.php'],
        'tests/GreetingTest.php',
        shouldStop: fn (): bool => true,
    );
    expect($receiptFail->status)->toBe('stopped')
        ->and($receiptFail->report['receipt_error'])->toStartWith('RECEIPT_FINALIZE_FAILED:')
        ->and($receiptFail->report)->not->toHaveKey('terminated_before_completion');

    app()->forgetInstance(RunTask::class);
    // Invalid parallel config fails closed before verification.
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    config(['molly.parallel_checks' => 'sometimes']);
    ChangeWriter::fake([mollyProposalFixture()])->preventStrayPrompts();
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->once()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldNotReceive('handle');
    $badParallel = app(RunTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    expect($badParallel->status)->toBe('failed')
        ->and($badParallel->report['error'])->toStartWith('PARALLEL_CONFIG_INVALID:');
    config(['molly.parallel_checks' => false]);
});
