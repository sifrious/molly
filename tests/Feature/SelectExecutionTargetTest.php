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

it('refuses an Orb request without a saved task, whatever target it names', function (string $targetId) {
    expect(fn () => app(SelectExecutionTarget::class)->handle(new ExecutionTargetRequest(ExecutionTargetKind::Orb, $targetId, 'Run on the build Orb.')))
        ->toThrow(RuntimeException::class, 'ORB_TASK_REQUIRED: An Orb runs a saved task.');
})->with(['orb-build-1', 'amp-thread-T-123']);

it('rejects an Orb request that names no Orb and requires nothing, before selection', function () {
    expect(fn () => new ExecutionTargetRequest(ExecutionTargetKind::Orb))
        ->toThrow(InvalidArgumentException::class, 'CONTRACT_FIELD_INVALID: An Orb execution target needs a target_id.');
});

it('accepts an Orb request that requires a runtime or model instead of naming an Orb', function () {
    $request = ExecutionTargetRequest::orb(null, 'OLLAMA', ' gpt-oss:20b ');

    expect($request->kind)->toBe(ExecutionTargetKind::Orb)
        ->and($request->targetId)->toBeNull()
        ->and($request->toArray())->toBe(['kind' => 'orb', 'target_id' => null, 'reason' => null, 'runtime' => 'ollama', 'model' => 'gpt-oss:20b'])
        ->and(ExecutionTargetRequest::fromArray($request->toArray()))->toEqual($request)
        ->and(ExecutionTargetRequest::orb(null, '', ' '))->toBeNull()
        ->and(ExecutionTargetRequest::local()->toArray())->toBe(['kind' => 'local', 'target_id' => null, 'reason' => 'Local execution is the default.']);
});

it('rejects an unknown runtime and a local request that requires an Orb capability', function () {
    expect(fn () => ExecutionTargetRequest::orb(null, 'codex'))
        ->toThrow(InvalidArgumentException::class, 'CONTRACT_FIELD_INVALID: execution_target.runtime must be ollama or amp.')
        ->and(fn () => new ExecutionTargetRequest(ExecutionTargetKind::Local, null, null, 'ollama'))
        ->toThrow(InvalidArgumentException::class, 'CONTRACT_FIELD_INVALID: A local execution target cannot require an Orb runtime or model.');
});
