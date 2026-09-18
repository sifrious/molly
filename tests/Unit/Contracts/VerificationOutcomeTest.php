<?php

use Sifrious\Molly\Contracts\VerificationOutcome;
use Sifrious\Molly\Tests\Unit\Contracts\ContractFixtures;

it('round-trips a verification outcome', function () {
    $outcome = ContractFixtures::outcome();

    expect(VerificationOutcome::fromJson($outcome->toJson())->toArray())->toBe($outcome->toArray());
});

it('keeps outcome state, policy, and failure action as separate fields', function () {
    $outcome = ContractFixtures::outcome();

    expect($outcome->state->value)->toBe('FAIL')
        ->and($outcome->policy->value)->toBe('required')
        ->and($outcome->failureAction->value)->toBe('retry')
        ->and($outcome->anotherAttemptPermitted)->toBeTrue();
});

it('rejects a required verifier that only warns', function () {
    $data = ContractFixtures::outcome()->toArray();
    $data['failure_action'] = 'warn';

    expect(fn () => VerificationOutcome::fromArray($data))
        ->toThrow(InvalidArgumentException::class, 'A required verifier cannot use the warn failure action.');
});
