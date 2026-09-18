<?php

use Sifrious\Molly\Contracts\TaskContract;
use Sifrious\Molly\Tests\Unit\Contracts\ContractFixtures;

it('round-trips a task contract', function () {
    $contract = ContractFixtures::task();

    expect(TaskContract::fromJson($contract->toJson())->toArray())->toBe($contract->toArray());
});

it('rejects an acceptance test that is also writable', function () {
    $data = ContractFixtures::task()->toArray();
    $data['allowed_write_paths'][] = 'tests/Feature/GreetingTest.php';

    expect(fn () => TaskContract::fromArray($data))
        ->toThrow(InvalidArgumentException::class, 'An acceptance test cannot also be writable.');
});

it('rejects an Orb request without a target id', function () {
    $data = ContractFixtures::task()->toArray();
    $data['execution_target'] = ['kind' => 'orb', 'target_id' => null, 'reason' => 'Use a remote Orb.'];

    expect(fn () => TaskContract::fromArray($data))
        ->toThrow(InvalidArgumentException::class, 'An Orb execution target needs a target_id.');
});
