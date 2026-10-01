<?php

use Illuminate\Support\Facades\File;
use Sifrious\Molly\Actions\CancelSeamRun;
use Sifrious\Molly\Actions\CreatePlan;
use Sifrious\Molly\Actions\CreateSeamPlan;
use Sifrious\Molly\Actions\ExecuteSeamStep;
use Sifrious\Molly\Actions\GuardSeamRevision;
use Sifrious\Molly\Actions\InspectSeamRun;
use Sifrious\Molly\Actions\RecordSeamAttempt;
use Sifrious\Molly\Actions\RequestSeamStep;
use Sifrious\Molly\Actions\WriteSeamTests;
use Sifrious\Molly\Seams\InstructionPacks;
use Sifrious\Molly\Seams\SeamError;

require_once __DIR__.'/../Support/SeamFixtures.php';

beforeEach(function () {
    $this->seamWorkspace = seamApplicationFixture();
    $this->seamPlan = app(CreatePlan::class)->handle('Verify readiness.', false);
    $this->revision = app(CreateSeamPlan::class)->handle($this->seamPlan->id, $this->seamWorkspace, 'laravel-framework/controllers', seamHttpContract());
    $this->revision = app(WriteSeamTests::class)->handle($this->revision->id, $this->revision->digest);
});

afterEach(fn () => File::deleteDirectory($this->seamWorkspace));

it('deduplicates queued requests and cancels before execution without advancing', function () {
    $request = app(RequestSeamStep::class);
    $run = $request->handle($this->revision->id, $this->revision->digest, 'harness', 'same-request', queued: false);
    expect($request->handle($this->revision->id, $this->revision->digest, 'harness', 'same-request', queued: false)->id)->toBe($run->id);
    expect(fn () => $request->handle($this->revision->id, $this->revision->digest, 'harness', 'another-request', queued: false))->toThrow(SeamError::class, 'RUN_ALREADY_ACTIVE');
    expect(app(CancelSeamRun::class)->handle($this->revision->id)['status'])->toBe('cancelling');
    $result = app(ExecuteSeamStep::class)->handle($run->id);
    expect($result->report['step_result']['error']['code'])->toBe('PROCESS_CANCELLED')
        ->and($this->revision->fresh()->status)->toBe('cancelled')->and($this->revision->fresh()->cursor)->toBe(0);
});

it('records interrupted attempts and permits a linked retry without rerunning the old request', function () {
    $request = app(RequestSeamStep::class);
    $run = $request->handle($this->revision->id, $this->revision->digest, 'harness', 'interrupted', queued: false);
    $run->update(['status' => 'running']);
    $result = app(ExecuteSeamStep::class)->handle($run->id);
    expect($result->report['step_result']['state'])->toBe('NOT_RUN')->and($this->revision->fresh()->active_run_id)->toBeNull();
    $retry = $request->handle($this->revision->id, $this->revision->digest, 'harness', 'retry', queued: false);
    expect($retry->id)->not->toBe($run->id);
    expect(app(ExecuteSeamStep::class)->handle($retry->id)->report['step_result']['state'])->toBe('PASS');
    expect(app(ExecuteSeamStep::class)->handle($run->id)->report['step_result']['state'])->toBe('NOT_RUN');
});

it('recovers the cursor if a worker stops after persisting its result', function () {
    $run = app(RequestSeamStep::class)->handle($this->revision->id, $this->revision->digest, 'harness', 'cursor-recovery', queued: false);
    app(ExecuteSeamStep::class)->handle($run->id);
    $this->revision->refresh()->update(['active_run_id' => $run->id, 'results' => [], 'cursor' => 0, 'status' => 'ready']);
    app(ExecuteSeamStep::class)->handle($run->id);
    expect($this->revision->fresh()->cursor)->toBe(1)->and($this->revision->fresh()->active_run_id)->toBeNull();
});

it('rejects changed instructions and changed protected tests', function () {
    $root = $this->seamWorkspace.'/resources/molly/seams/laravel-framework/controllers';
    File::copyDirectory(app(InstructionPacks::class)->bundledPath().'/laravel-framework/controllers', $root);
    File::append($root.'/instructions.md', "\nLocal behavior requirement.\n");
    expect(fn () => app(GuardSeamRevision::class)->handle($this->revision))->toThrow(SeamError::class, 'INSTRUCTION_DIGEST_CHANGED');
    File::deleteDirectory($this->seamWorkspace.'/resources');
    File::append($this->seamWorkspace.'/tests/Feature/ReadinessTest.php', "\n// changed\n");
    expect(fn () => app(GuardSeamRevision::class)->handle($this->revision))->toThrow(SeamError::class, 'TEST_DIGEST_CHANGED');
});

it('refuses missing and tampered evidence rather than trusting saved PASS text', function () {
    expect(fn () => app(RecordSeamAttempt::class)->verify([], $this->seamWorkspace))->toThrow(SeamError::class, 'ARTIFACT_MISSING');
    $run = app(RequestSeamStep::class)->handle($this->revision->id, $this->revision->digest, 'harness', 'tamper-check', queued: false);
    $run = app(ExecuteSeamStep::class)->handle($run->id);
    File::append($run->report['seam']['artifacts'][0]['path'], 'tampered');
    expect(app(InspectSeamRun::class)->handle($this->revision->id)['blockers'])->toContain('ARTIFACT_DIGEST_MISMATCH');
    expect(fn () => app(RequestSeamStep::class)->handle($this->revision->id, $this->revision->digest, 'pre', 'next', queued: false))->toThrow(SeamError::class, 'ARTIFACT_DIGEST_MISMATCH');
});

it('freezes local instruction edits in a new revision while preserving earlier evidence', function () {
    $root = $this->seamWorkspace.'/resources/molly/seams/laravel-framework/controllers';
    File::copyDirectory(app(InstructionPacks::class)->bundledPath().'/laravel-framework/controllers', $root);
    File::append($root.'/instructions.md', "\nThe response must remain independent of session state.\n");
    $next = app(CreateSeamPlan::class)->handle($this->seamPlan->id, $this->seamWorkspace, 'laravel-framework/controllers', seamHttpContract());
    expect($next->id)->not->toBe($this->revision->id)->and($next->number)->toBe(2)
        ->and($next->snapshot['pack']['local_changes'])->toBe(['instructions.md'])
        ->and($this->revision->fresh()->status)->toBe('superseded')
        ->and($this->revision->fresh()->snapshot['pack']['files']['instructions.md']['contents'])->not->toContain('remain independent');
});
