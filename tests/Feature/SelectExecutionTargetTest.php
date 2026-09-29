<?php

use Sifrious\Molly\Contracts\ExecutionTargetKind;
use Sifrious\Molly\Contracts\ExecutionTargetRequest;
use Sifrious\Molly\Execution\SelectExecutionTarget;

it('selects local execution by default', function () {
    $snapshot = app(SelectExecutionTarget::class)->handle();

    expect($snapshot->kind)->toBe(ExecutionTargetKind::Local)
        ->and($snapshot->targetId)->toBe('local')
        ->and($snapshot->selectionReason)->toContain('default');
});

it('refuses an Orb request that is only an Amp thread observation', function () {
    expect(fn () => app(SelectExecutionTarget::class)->handle(new ExecutionTargetRequest(ExecutionTargetKind::Orb, 'amp-thread-observation')))
        ->toThrow(RuntimeException::class, 'ORB_UNVERIFIED');
});

it('refuses every Orb request, including one that names a target', function (string $targetId) {
    expect(fn () => app(SelectExecutionTarget::class)->handle(new ExecutionTargetRequest(ExecutionTargetKind::Orb, $targetId, 'Run on the build Orb.')))
        ->toThrow(RuntimeException::class, 'ORB_UNVERIFIED: This Molly release runs tasks only on the local machine.');
})->with(['orb-build-1', 'amp-thread-T-123']);

it('rejects an Orb request without a target before selection', function () {
    expect(fn () => new ExecutionTargetRequest(ExecutionTargetKind::Orb))
        ->toThrow(InvalidArgumentException::class, 'CONTRACT_FIELD_INVALID: An Orb execution target needs a target_id.');
});
