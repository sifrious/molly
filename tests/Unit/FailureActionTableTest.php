<?php

use Sifrious\Molly\Verification\CompletionGate;
use Sifrious\Molly\Verification\FailureAction;
use Sifrious\Molly\Verification\VerificationState;
use Sifrious\Molly\Verification\VerifierPolicy;

it('keeps outcome state, policy, and failure action as separate fields for every combination', function (VerificationState $state, VerifierPolicy $policy, FailureAction $action, bool $completed) {
    $result = app(CompletionGate::class)->evaluate([
        'pest' => ['state' => $state, 'policy' => $policy, 'failure_action' => $action],
    ]);

    expect($result['completed'])->toBe($completed)
        ->and($result['outcomes']['pest'])->toBe([
            'state' => $state->value,
            'policy' => $policy->value,
        ]);
})->with(function () {
    $rows = [];
    foreach (VerificationState::cases() as $state) {
        foreach (VerifierPolicy::cases() as $policy) {
            foreach (FailureAction::cases() as $action) {
                $completed = $policy === VerifierPolicy::Advisory || $state === VerificationState::Pass;
                $rows[$state->value.' '.$policy->value.' '.$action->value] = [$state, $policy, $action, $completed];
            }
        }
    }

    return $rows;
});

it('never lets an advisory warn convert a required Pest failure into completion', function () {
    $result = app(CompletionGate::class)->evaluate([
        'pest' => ['state' => VerificationState::Fail, 'policy' => VerifierPolicy::Required, 'failure_action' => FailureAction::Retry],
        'tarpit' => ['state' => VerificationState::Pass, 'policy' => VerifierPolicy::Required, 'failure_action' => FailureAction::Retry],
        'clever' => ['state' => VerificationState::Pass, 'policy' => VerifierPolicy::Advisory, 'failure_action' => FailureAction::Warn],
        'typesafe' => ['state' => VerificationState::Pass, 'policy' => VerifierPolicy::Advisory, 'failure_action' => FailureAction::Warn],
    ]);

    expect($result['completed'])->toBeFalse()
        ->and($result['blockers'])->toBe(['pest']);
});
