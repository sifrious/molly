<?php

use Sifrious\Molly\Classification\ClassificationDecision;
use Sifrious\Molly\Classification\ClassifyRunEvidence;
use Sifrious\Molly\Classification\DetectLaravelAiClassification;
use Sifrious\Molly\Classification\FallbackClassificationAdapter;
use Sifrious\Molly\Classification\ResolveClassificationAdapter;
use Sifrious\Molly\Models\Task;

it('keeps classification advisory and never upgrades a failed Pest run', function () {
    $adapter = new FallbackClassificationAdapter;

    $failed = $adapter->classify(['run_id' => 'run-1', 'verification' => ['status' => 'failed', 'junit' => '/tmp/pest.xml']]);
    $passed = $adapter->classify(['run_id' => 'run-2', 'verification' => ['status' => 'passed']]);

    expect($failed->action)->toBe('retry')
        ->and($failed->deterministicFollowUp)->toBe('keep_failed')
        ->and($passed->deterministicFollowUp)->toBe('complete_if_required_gates_pass')
        ->and($adapter->name())->toBe('molly.fallback')
        ->and($failed->confidence)->toBeNull()
        ->and($failed->probability)->toBeNull()
        ->and($failed->provider)->toBeNull()
        ->and($failed->model)->toBeNull()
        ->and($failed->laravelAiVersion)->toBeNull()
        ->and($failed->fallbackReason)->toBeNull()
        ->and($failed->toArray()['provenance_status'])->toBe(ClassificationDecision::PROVENANCE_NOT_MEASURED)
        ->and($passed->toArray()['provenance_status'])->toBe(ClassificationDecision::PROVENANCE_NOT_MEASURED);
});

it('does not claim Laravel AI decide support before the 1.x API is installed', function () {
    $detect = new DetectLaravelAiClassification;

    expect($detect->supportsStructuredAgents())->toBeTrue()
        ->and($detect->supportsDecide())->toBeFalse()
        ->and($detect->adapter())->toBe('molly.fallback')
        ->and($detect->version())->not->toBeNull();
});

it('uses the deterministic fallback when Jev is globally disabled', function () {
    config(['molly.jev.enabled' => false]);

    expect(app(ResolveClassificationAdapter::class)->handle())
        ->toBeInstanceOf(FallbackClassificationAdapter::class);
});

it('keeps a failed Pest run failed while the Laravel AI decide capability is unavailable', function () {
    $task = Task::create(['prompt' => 'Return Hello.', 'workspace' => sys_get_temp_dir(), 'paths' => ['app/Greeting.php'], 'test_path' => 'tests/GreetingTest.php']);
    $run = $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => ['verification' => ['status' => 'failed']]]);
    $decision = app(ClassifyRunEvidence::class)->handle(['run_id' => $run->id, 'verification' => ['status' => 'failed', 'junit' => 'pest.xml']], $run);

    expect(app(ResolveClassificationAdapter::class)->handle())->toBeInstanceOf(FallbackClassificationAdapter::class)
        ->and($decision->adapter)->toBe('molly.fallback')
        ->and($decision->deterministicFollowUp)->toBe('keep_failed')
        ->and($run->fresh()->report['classification']['advisory'])->toBeTrue()
        ->and($run->fresh()->status)->toBe('failed');
});
