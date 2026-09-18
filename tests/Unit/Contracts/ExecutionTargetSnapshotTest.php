<?php

use Sifrious\Molly\Contracts\ExecutionTargetSnapshot;
use Sifrious\Molly\Tests\Unit\Contracts\ContractFixtures;

it('round-trips an execution target snapshot', function () {
    $snapshot = ContractFixtures::target();

    expect(ExecutionTargetSnapshot::fromJson($snapshot->toJson())->toArray())->toBe($snapshot->toArray());
});

it('rejects an Orb snapshot that claims the local target', function () {
    $data = ContractFixtures::target()->toArray();
    $data['kind'] = 'orb';

    expect(fn () => ExecutionTargetSnapshot::fromArray($data))
        ->toThrow(InvalidArgumentException::class, 'An Orb snapshot cannot use the local target_id.');
});
