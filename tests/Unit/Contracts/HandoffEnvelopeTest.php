<?php

use Sifrious\Molly\Contracts\HandoffEnvelope;
use Sifrious\Molly\Tests\Unit\Contracts\ContractFixtures;

it('round-trips a handoff envelope', function () {
    $handoff = ContractFixtures::handoff();

    expect(HandoffEnvelope::fromJson($handoff->toJson())->toArray())->toBe($handoff->toArray());
});

it('rejects a handoff that makes a protected test writable', function () {
    $data = ContractFixtures::handoff()->toArray();
    $data['allowed_paths'][] = 'tests/Feature/GreetingTest.php';

    expect(fn () => HandoffEnvelope::fromArray($data))
        ->toThrow(InvalidArgumentException::class, 'Protected tests cannot also be writable.');
});
