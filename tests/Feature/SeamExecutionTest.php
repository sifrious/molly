<?php

use Illuminate\Support\Facades\File;
use Sifrious\Molly\Actions\AcknowledgeSeamHandoff;
use Sifrious\Molly\Actions\CreatePlan;
use Sifrious\Molly\Actions\CreateSeamPlan;
use Sifrious\Molly\Actions\ExecuteSeamStep;
use Sifrious\Molly\Actions\InspectSeamRun;
use Sifrious\Molly\Actions\LockProtectedTest;
use Sifrious\Molly\Actions\RequestSeamStep;
use Sifrious\Molly\Actions\WriteSeamTests;
use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Seams\SeamError;

require_once __DIR__.'/../Support/SeamFixtures.php';

beforeEach(function () {
    $this->seamWorkspace = seamApplicationFixture();
    $this->seamPlan = app(CreatePlan::class)->handle('Verify readiness through the registered controller.', false);
});

afterEach(fn () => File::deleteDirectory($this->seamWorkspace));

function executeSeamTestStep(SeamRevision $revision, string $step, string $key = ''): array
{
    $run = app(RequestSeamStep::class)->handle($revision->id, $revision->digest, $step, $key ?: $step, 'verifier', false);
    $result = app(ExecuteSeamStep::class)->handle($run->id);

    return $result->report['step_result'];
}

it('freezes an idempotent revision and refuses POST before its prerequisites', function () {
    $create = app(CreateSeamPlan::class);
    $revision = $create->handle($this->seamPlan->id, $this->seamWorkspace, 'laravel-framework/controllers', seamHttpContract());
    expect($create->handle($this->seamPlan->id, $this->seamWorkspace, 'laravel-framework/controllers', seamHttpContract())->id)->toBe($revision->id);
    app(WriteSeamTests::class)->handle($revision->id, $revision->digest);
    expect($create->handle($this->seamPlan->id, $this->seamWorkspace, 'laravel-framework/controllers', seamHttpContract())->id)->toBe($revision->id);
    expect(fn () => app(RequestSeamStep::class)->handle($revision->id, $revision->digest, 'post', 'try-post', queued: false))
        ->toThrow(SeamError::class, 'PLAN_STEP_NOT_ALLOWED');
    expect(fn () => app(LockProtectedTest::class)->handle($revision->fresh()->task_id, true))
        ->toThrow(SeamError::class, 'PLAN_STEP_NOT_ALLOWED');
});

it('executes meaningful RED, human lock, GREEN and targeted sensitivity through real Pest', function () {
    $revision = app(CreateSeamPlan::class)->handle($this->seamPlan->id, $this->seamWorkspace, 'laravel-framework/controllers', seamHttpContract());
    $revision = app(WriteSeamTests::class)->handle($revision->id, $revision->digest);
    foreach (['harness', 'pre'] as $step) {
        $result = executeSeamTestStep($revision, $step);
        expect($result['state'])->toBe('PASS', json_encode($result));
    }
    $lock = executeSeamTestStep($revision, 'lock', 'unapproved-lock');
    expect($lock['state'])->toBe('REVIEW_REQUIRED');
    app(LockProtectedTest::class)->handle($revision->task_id, true);
    expect(executeSeamTestStep($revision, 'lock', 'approved-lock')['state'])->toBe('PASS');

    File::ensureDirectoryExists($this->seamWorkspace.'/app/Http/Controllers');
    File::put($this->seamWorkspace.'/app/Http/Controllers/ReadyController.php', "<?php\nnamespace App\\Http\\Controllers;\nclass ReadyController { public function __invoke() { return response()->json(['ready' => true]); } }\n");
    File::put($this->seamWorkspace.'/routes/web.php', "<?php\nIlluminate\\Support\\Facades\\Route::get('/ready', App\\Http\\Controllers\\ReadyController::class);\n");
    foreach (['implementation', 'post', 'sensitivity', 'cleanup'] as $step) {
        $result = executeSeamTestStep($revision, $step);
        expect($result['state'])->toBe('PASS', json_encode($result));
    }
    $handoff = app(InspectSeamRun::class)->handle($revision->id)['handoff'];
    app(AcknowledgeSeamHandoff::class)->handle($revision->id, $handoff['revision_digest'], $handoff['candidate_digest'], $handoff['evidence_digest'], 'receiving-verifier');
    expect(executeSeamTestStep($revision, 'handoff')['state'])->toBe('PASS');
    $report = app(InspectSeamRun::class)->handle($revision->id);
    expect(app(AcknowledgeSeamHandoff::class)->handle($revision->id, $handoff['revision_digest'], $handoff['candidate_digest'], $handoff['evidence_digest'], 'receiving-verifier')['recipient'])->toBe('receiving-verifier');
    expect($report['completed'])->toBeTrue()->and($report['blockers'])->toBe([])->and($report['attempts'])->toHaveCount(9);
    $repeat = app(RequestSeamStep::class)->handle($revision->id, $revision->digest, 'post', 'post', queued: false);
    expect(app(ExecuteSeamStep::class)->handle($repeat->id)->id)->toBe($repeat->id)
        ->and($revision->task->runs()->count())->toBe(9);
});

it('verifies a registered command with the same execution and receipt actions', function () {
    prepareSeamConsoleFixture($this->seamWorkspace);
    $revision = app(CreateSeamPlan::class)->handle($this->seamPlan->id, $this->seamWorkspace, 'laravel-framework/commands', seamConsoleContract());
    $revision = app(WriteSeamTests::class)->handle($revision->id, $revision->digest);
    foreach (['harness', 'pre'] as $step) {
        $result = executeSeamTestStep($revision, $step);
        expect($result['state'])->toBe('PASS', json_encode($result));
    }
    app(LockProtectedTest::class)->handle($revision->task_id, true);
    expect(executeSeamTestStep($revision, 'lock')['state'])->toBe('PASS');
    $path = $this->seamWorkspace.'/app/Console/ReadyCommand.php';
    File::put($path, str_replace(["'Not ready'", 'return 1;'], ["'Ready'", 'return 0;'], File::get($path)));
    foreach (['implementation', 'post', 'sensitivity', 'cleanup'] as $step) {
        $result = executeSeamTestStep($revision, $step);
        expect($result['state'])->toBe('PASS', json_encode($result));
    }
    $handoff = app(InspectSeamRun::class)->handle($revision->id)['handoff'];
    app(AcknowledgeSeamHandoff::class)->handle($revision->id, $handoff['revision_digest'], $handoff['candidate_digest'], $handoff['evidence_digest'], 'command-verifier');
    expect(executeSeamTestStep($revision, 'handoff')['state'])->toBe('PASS');
    expect(app(InspectSeamRun::class)->handle($revision->id)['completed'])->toBeTrue();

});

it('records already implemented behavior without claiming historical RED', function () {
    prepareSeamConsoleFixture($this->seamWorkspace);
    $path = $this->seamWorkspace.'/app/Console/ReadyCommand.php';
    File::put($path, str_replace(["'Not ready'", 'return 1;'], ["'Ready'", 'return 0;'], File::get($path)));
    $contract = [...seamConsoleContract(), 'verification_path' => 'already_implemented'];
    $revision = app(CreateSeamPlan::class)->handle($this->seamPlan->id, $this->seamWorkspace, 'laravel-framework/commands', $contract);
    $revision = app(WriteSeamTests::class)->handle($revision->id, $revision->digest);
    expect(executeSeamTestStep($revision, 'harness')['state'])->toBe('PASS');
    $result = executeSeamTestStep($revision, 'pre');
    expect($result['state'])->toBe('PASS')->and($result['expected']['historical_red'])->toBeFalse()
        ->and($revision->task->source['red_baseline_required'])->toBeFalse();
    app(LockProtectedTest::class)->handle($revision->task_id, true);
});

it('rejects assertion failure caused by a different baseline response', function () {
    $contract = seamHttpContract();
    $contract['cases'][0]['before']['status'] = 403;
    $revision = app(CreateSeamPlan::class)->handle($this->seamPlan->id, $this->seamWorkspace, 'laravel-framework/controllers', $contract);
    $revision = app(WriteSeamTests::class)->handle($revision->id, $revision->digest);
    expect(executeSeamTestStep($revision, 'harness')['state'])->toBe('PASS');
    $result = executeSeamTestStep($revision, 'pre');
    expect($result['state'])->toBe('FAIL')->and($result['error']['code'])->toBe('RED_REASON_MISMATCH');
});
