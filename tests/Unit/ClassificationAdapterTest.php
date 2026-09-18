<?php

use Sifrious\Molly\Classification\ClassifyRunEvidence;
use Sifrious\Molly\Classification\DetectLaravelAiClassification;
use Sifrious\Molly\Classification\FallbackClassificationAdapter;
use Sifrious\Molly\Classification\LaravelAiClassificationAdapter;
use Sifrious\Molly\Classification\ResolveClassificationAdapter;
use Sifrious\Molly\Models\Task;

it('keeps classification advisory and never upgrades a failed Pest run', function () {
    $adapter = new FallbackClassificationAdapter;

    $failed = $adapter->classify(['run_id' => 'run-1', 'verification' => ['status' => 'failed', 'junit' => '/tmp/pest.xml']]);
    $passed = $adapter->classify(['run_id' => 'run-2', 'verification' => ['status' => 'passed']]);

    expect($failed->action)->toBe('retry')
        ->and($failed->deterministicFollowUp)->toBe('keep_failed')
        ->and($passed->deterministicFollowUp)->toBe('complete_if_required_gates_pass')
        ->and($adapter->name())->toBe('molly.fallback');
});

it('detects Laravel AI structured output without claiming an unreleased classification API', function () {
    $detect = new DetectLaravelAiClassification;

    expect($detect->supportsStructuredAgents())->toBeTrue()
        ->and($detect->adapter())->toBe('laravel-ai.structured')
        ->and($detect->version())->not->toBeNull();
});

it('uses the Laravel AI adapter when structured agents exist and still keeps a failed Pest run failed', function () {
    $task = Task::create(['prompt' => 'Return Hello.', 'workspace' => sys_get_temp_dir(), 'paths' => ['app/Greeting.php'], 'test_path' => 'tests/GreetingTest.php']);
    $run = $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => ['verification' => ['status' => 'failed']]]);
    $decision = app(ClassifyRunEvidence::class)->handle(['run_id' => $run->id, 'verification' => ['status' => 'failed', 'junit' => 'pest.xml']], $run);

    expect(app(ResolveClassificationAdapter::class)->handle())->toBeInstanceOf(LaravelAiClassificationAdapter::class)
        ->and($decision->adapter)->toBe('laravel-ai.structured')
        ->and($decision->deterministicFollowUp)->toBe('keep_failed')
        ->and($run->fresh()->report['classification']['advisory'])->toBeTrue()
        ->and($run->fresh()->status)->toBe('failed');
});
