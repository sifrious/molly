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

it('matches the Bloom Swift task contract fixture byte for byte', function () {
    $swift = file_get_contents(dirname(__DIR__, 3).'/docs/handoffs/bloom-adapter/Tests/BloomCoreTests/MollyContractFixtures.swift');
    expect($swift)->not->toBeFalse();
    expect(preg_match('/static let contractJSON = """\s*(.+?)\s*"""/s', $swift, $matches))->toBe(1);
    $expected = strtr($matches[1], [
        '\\(taskID)' => ContractFixtures::TASK_ID,
        '\\(workspaceID)' => ContractFixtures::WORKSPACE_ID,
        '\\(workspacePath)' => '/tmp/bloom/molly-workspace',
        '\\(branch)' => 'bloom/hello',
        '\\(sha)' => ContractFixtures::SHA,
        '\\(testID)' => ContractFixtures::TEST_ID,
        '\\(digest)' => ContractFixtures::DIGEST,
    ]);

    expect(ContractFixtures::task()->toJson())->toBe($expected)
        ->and(TaskContract::fromJson($expected)->toArray())->toBe(ContractFixtures::task()->toArray());
});
