<?php

use Sifrious\Molly\Contracts\VerificationOutcome;
use Sifrious\Molly\Tests\Unit\Contracts\ContractFixtures;

it('round-trips a verification outcome', function () {
    $outcome = ContractFixtures::outcome();

    expect(VerificationOutcome::fromJson($outcome->toJson())->toArray())->toBe($outcome->toArray());
});

it('matches the Bloom Swift Pest failure fixture byte for byte', function () {
    $swift = file_get_contents(dirname(__DIR__, 3).'/docs/handoffs/bloom-adapter/Tests/BloomCoreTests/MollyContractFixtures.swift');
    expect($swift)->not->toBeFalse();
    expect(preg_match('/static let pestFailJSON = """\s*(.+?)\s*"""/s', $swift, $matches))->toBe(1);
    $expected = str_replace('\\(digest)', ContractFixtures::DIGEST, $matches[1]);

    expect(ContractFixtures::outcome()->toJson())->toBe($expected)
        ->and(VerificationOutcome::fromJson($expected)->toArray())->toBe(ContractFixtures::outcome()->toArray());
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
