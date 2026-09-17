<?php

use Sifrious\Molly\Verification\CompletionGate;
use Sifrious\Molly\Verification\VerificationState;
use Sifrious\Molly\Verification\VerifierPolicy;

it('maps legacy verifier statuses into explicit states', function (?string $status, VerificationState $expected) {
    expect(VerificationState::fromObserved($status))->toBe($expected);
})->with([
    'passed' => ['passed', VerificationState::Pass],
    'failed' => ['failed', VerificationState::Fail],
    'review required' => ['review_required', VerificationState::ReviewRequired],
    'missing' => [null, VerificationState::NotRun],
    'unknown' => ['unavailable', VerificationState::NotRun],
]);

it('blocks every non-pass state when the verifier is required', function (VerificationState $state) {
    $result = app(CompletionGate::class)->evaluate([
        'pest' => ['state' => $state, 'policy' => VerifierPolicy::Required],
    ]);

    expect($result['completed'])->toBeFalse()
        ->and($result['blockers'])->toBe(['pest'])
        ->and($result['outcomes']['pest'])->toBe([
            'state' => $state->value,
            'policy' => 'required',
        ]);
})->with([
    VerificationState::Fail,
    VerificationState::ReviewRequired,
    VerificationState::NotRun,
]);

it('allows a required pass', function () {
    $result = app(CompletionGate::class)->evaluate([
        'pest' => ['state' => VerificationState::Pass, 'policy' => VerifierPolicy::Required],
    ]);

    expect($result['completed'])->toBeTrue()
        ->and($result['blockers'])->toBe([]);
});

it('keeps advisory outcomes visible without making them completion blockers', function (VerificationState $state) {
    $result = app(CompletionGate::class)->evaluate([
        'pest' => ['state' => VerificationState::Pass, 'policy' => VerifierPolicy::Required],
        'tarpit' => ['state' => $state, 'policy' => VerifierPolicy::Advisory],
    ]);

    expect($result['completed'])->toBeTrue()
        ->and($result['blockers'])->toBe([])
        ->and($result['outcomes']['tarpit']['state'])->toBe($state->value)
        ->and($result['outcomes']['tarpit']['policy'])->toBe('advisory');
})->with([
    VerificationState::Fail,
    VerificationState::ReviewRequired,
    VerificationState::NotRun,
]);
