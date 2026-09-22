<?php

use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Sifrious\Molly\Classification\ClassifyRunEvidence;
use Sifrious\Molly\Classification\DetectLaravelAiClassification;
use Sifrious\Molly\Classification\FallbackClassificationAdapter;
use Sifrious\Molly\Classification\LaravelAiClassificationAdapter;
use Sifrious\Molly\Classification\ResolveClassificationAdapter;
use Sifrious\Molly\Models\Task;

it('keeps deterministic follow-up authoritative', function () {
    $adapter = new FallbackClassificationAdapter;

    $failed = $adapter->classify(['run_id' => 'run-1', 'verification' => ['status' => 'failed', 'junit' => '/tmp/pest.xml']]);
    $passed = $adapter->classify(['run_id' => 'run-2', 'verification' => ['status' => 'passed']]);

    expect($failed->deterministicFollowUp)->toBe('keep_failed')
        ->and($passed->deterministicFollowUp)->toBe('complete_if_required_gates_pass');
});

it('uses fallback capability detection on the stable Laravel AI baseline', function () {
    $detect = new DetectLaravelAiClassification;

    if ($detect->supportsDecide()) {
        $this->markTestSkipped('This lane has the optional Laravel AI 1.x decide capability.');
    }

    expect($detect->supportsChoice())->toBeFalse()
        ->and($detect->adapter())->toBe('molly.fallback')
        ->and($detect->version())->not->toBeNull();
});

it('detects the live Laravel AI Jev capabilities when 1.x is installed', function () {
    $detect = new DetectLaravelAiClassification;

    if (! $detect->supportsDecide()) {
        $this->markTestSkipped('Laravel AI 1.x decide capability is not installed in this lane.');
    }

    expect($detect->supportsChoice())->toBeTrue()
        ->and($detect->adapter())->toBe('laravel-ai.decide')
        ->and($detect->version())->not->toBeNull();
});

it('uses deterministic fallback when Jev is globally disabled', function () {
    config(['molly.jev.enabled' => false]);

    expect(app(ResolveClassificationAdapter::class)->handle())
        ->toBeInstanceOf(FallbackClassificationAdapter::class);
});

it('uses Laravel AI decide when Jev is enabled but never upgrades a failed run', function () {
    $detect = new DetectLaravelAiClassification;
    if (! $detect->supportsDecide()) {
        $this->markTestSkipped('Laravel AI 1.x decide capability is not installed in this lane.');
    }

    config(['molly.jev.enabled' => true]);

    Classification::fake(fn (ClassificationPrompt $prompt) => [
        'decision' => new BooleanAnswer($prompt->contains('failed') ? 0.95 : 0.05),
    ]);

    $task = Task::create([
        'prompt' => 'Return Hello.',
        'workspace' => sys_get_temp_dir(),
        'paths' => ['app/Greeting.php'],
        'test_path' => 'tests/GreetingTest.php',
    ]);
    $run = $task->runs()->create([
        'prompt' => $task->prompt,
        'workspace' => $task->workspace,
        'status' => 'failed',
        'report' => ['verification' => ['status' => 'failed']],
    ]);

    $decision = app(ClassifyRunEvidence::class)->handle([
        'run_id' => $run->id,
        'verification' => ['status' => 'failed', 'junit' => 'pest.xml'],
    ], $run);

    expect(app(ResolveClassificationAdapter::class)->handle())->toBeInstanceOf(LaravelAiClassificationAdapter::class)
        ->and($decision->adapter)->toBe('laravel-ai.decide')
        ->and($decision->action)->toBe('retry')
        ->and($decision->deterministicFollowUp)->toBe('keep_failed')
        ->and($run->fresh()->report['classification']['advisory'])->toBeTrue()
        ->and($run->fresh()->status)->toBe('failed');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool =>
        $prompt->asks('decision') && $prompt->contains('failed')
    );
});
