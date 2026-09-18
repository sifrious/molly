<?php

use Sifrious\Molly\Contracts\RunIdentity;
use Sifrious\Molly\Tests\Unit\Contracts\ContractFixtures;

it('round-trips a run identity', function () {
    $identity = ContractFixtures::run();

    expect(RunIdentity::fromJson($identity->toJson())->toArray())->toBe($identity->toArray());
});

it('keeps parent run and handoff identifiers when present', function () {
    $data = ContractFixtures::run()->toArray();
    $data['parent_run_id'] = '99999999-9999-4999-8999-999999999999';
    $data['handoff_id'] = ContractFixtures::HANDOFF_ID;

    $identity = RunIdentity::fromArray($data);

    expect($identity->parentRunId)->toBe('99999999-9999-4999-8999-999999999999')
        ->and($identity->handoffId)->toBe(ContractFixtures::HANDOFF_ID);
});
