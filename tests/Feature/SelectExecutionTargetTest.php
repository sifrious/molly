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
