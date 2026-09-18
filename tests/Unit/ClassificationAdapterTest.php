<?php

use Sifrious\Molly\Classification\FallbackClassificationAdapter;

it('keeps classification advisory and never upgrades a failed Pest run', function () {
    $adapter = new FallbackClassificationAdapter;

    $failed = $adapter->classify(['run_id' => 'run-1', 'verification' => ['status' => 'failed', 'junit' => '/tmp/pest.xml']]);
    $passed = $adapter->classify(['run_id' => 'run-2', 'verification' => ['status' => 'passed']]);

    expect($failed->action)->toBe('retry')
        ->and($failed->deterministicFollowUp)->toBe('keep_failed')
        ->and($passed->deterministicFollowUp)->toBe('complete_if_required_gates_pass')
        ->and($adapter->name())->toBe('molly.fallback');
});
