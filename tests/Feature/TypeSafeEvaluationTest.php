<?php

use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Sifrious\Molly\Actions\AnswerPlan;
use Sifrious\Molly\Actions\CreatePlan;
use Sifrious\Molly\Actions\EvaluateWithTypeSafe;
use Sifrious\Molly\Actions\SuggestPlanReview;
use Sifrious\Molly\Classification\DetectLaravelAiClassification;
use Sifrious\Molly\PlanningGuide;

beforeEach(function (): void {
    skipWithoutJevCapability(app(DetectLaravelAiClassification::class)->supportsChoice(), 'Live Jev classification requires the optional Laravel AI classification capability.');

    config([
        'molly.jev.enabled' => true,
        'ai.providers.typesafe.key' => 'test-key',
    ]);
});

function jevEvidence(): array
{
    return ['prompt' => 'Fix the flag.', 'verification' => ['status' => 'failed'], 'review' => ['findings' => []]];
}

function fakeJevChoice(string $question, string $choice, array $options, float $confidence = 0.9): void
{
    $probabilities = array_fill_keys($options, 0.0);
    $probabilities[$choice] = 1.0;

    Classification::fake([
        [$question => new ChoiceAnswer($choice, $probabilities, $confidence)],
    ]);
}

it('uses Laravel AI classification for each allowed task action', function (string $choice): void {
    fakeJevChoice('next_action', $choice, ['continue', 'retry', 'stop', 'needs_review']);

    $result = app(EvaluateWithTypeSafe::class)->handle(jevEvidence());

    expect($result)->toMatchArray([
        'status' => 'evaluated',
        'next_action' => $choice,
        'confidence' => 0.9,
        'provider' => 'typesafe',
    ]);

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->asks('next_action') && $prompt->contains('Fix the flag.')
    );
})->with(['continue', 'retry', 'stop', 'needs_review']);

it('does not classify when the Jev gate is off', function (): void {
    Classification::fake();
    config(['molly.jev.enabled' => false]);

    $result = app(EvaluateWithTypeSafe::class)->handle(jevEvidence());

    expect($result)->toMatchArray([
        'status' => 'disabled',
        'next_action' => null,
        'reason' => 'jev_disabled',
    ]);

    Classification::assertNothingClassified();
});

it('rejects invalid configuration before classification', function (string $key, mixed $value): void {
    Classification::fake();
    config(["molly.jev.$key" => $value]);

    expect(app(EvaluateWithTypeSafe::class)->handle(jevEvidence())['reason'])->toBe('invalid_config');

    Classification::assertNothingClassified();
})->with([
    ['model', ''],
    ['timeout', 0],
    ['timeout', 121],
    ['confidence_threshold', 1.1],
    ['confidence_threshold', '0.8'],
    ['instructions', ''],
]);

it('rejects incomplete or oversized evidence before classification', function (array $evidence): void {
    Classification::fake();

    expect(app(EvaluateWithTypeSafe::class)->handle($evidence)['reason'])->toBe('invalid_evidence');

    Classification::assertNothingClassified();
})->with([
    fn () => [],
    fn () => array_replace(jevEvidence(), ['prompt' => str_repeat('x', 32769)]),
]);

it('falls back when confidence is below the configured threshold', function (): void {
    fakeJevChoice('next_action', 'continue', ['continue', 'retry', 'stop', 'needs_review'], 0.79);

    expect(app(EvaluateWithTypeSafe::class)->handle(jevEvidence()))->toMatchArray([
        'status' => 'needs_review',
        'next_action' => 'needs_review',
        'confidence' => 0.79,
        'reason' => 'low_confidence',
    ]);
});

it('accepts confidence exactly at the threshold', function (): void {
    fakeJevChoice('next_action', 'retry', ['continue', 'retry', 'stop', 'needs_review'], 0.8);

    expect(app(EvaluateWithTypeSafe::class)->handle(jevEvidence())['next_action'])->toBe('retry');
});

it('turns provider failures into a bounded review decision', function (): void {
    Classification::fake(function (): array {
        throw new RuntimeException('provider unavailable');
    });

    expect(app(EvaluateWithTypeSafe::class)->handle(jevEvidence()))->toMatchArray([
        'status' => 'needs_review',
        'next_action' => 'needs_review',
        'reason' => 'provider_error',
    ]);
});

it('saves a cited planning focus without changing plan answers', function (string $focus): void {
    $plan = app(CreatePlan::class)->handle('Show the pending tasks.');
    $plan = app(AnswerPlan::class)->handle($plan->id, 'outcome', 'List pending tasks.');

    fakeJevChoice('focus', $focus, ['outcome', 'state', 'laravel', 'boundaries', 'verification']);

    $result = app(SuggestPlanReview::class)->handle($plan);
    $step = collect(app(PlanningGuide::class)->steps())->firstWhere('id', $focus);

    expect($result)->toMatchArray([
        'status' => 'evaluated',
        'focus' => $focus,
        'question' => $step['question'],
    ])
        ->and(array_column($result['sources'], 'id'))->toBe($step['source_ids'])
        ->and($plan->fresh()->answers)->toBe(['outcome' => 'List pending tasks.'])
        ->and($plan->fresh()->nextStep()['id'])->toBe('state');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->asks('focus') && $prompt->contains('Show the pending tasks.')
    );
})->with(['outcome', 'state', 'laravel', 'boundaries', 'verification']);

it('does not invent a planning question when Jev is disabled', function (): void {
    Classification::fake();
    config(['molly.jev.enabled' => false]);

    $plan = app(CreatePlan::class)->handle('List tasks.');
    $result = app(SuggestPlanReview::class)->handle($plan);

    expect($result['focus'])->toBeNull()
        ->and($result['question'])->toBeNull()
        ->and($result['sources'])->toBe([]);

    Classification::assertNothingClassified();
});

it('assesses commit quality with a fixed bounded choice rubric', function (): void {
    fakeJevChoice('next_action', 'continue', ['continue', 'retry', 'stop', 'needs_review']);

    $result = app(EvaluateWithTypeSafe::class)->commit([
        'prompt' => 'Assess this PHP diff.',
        'verification' => ['diff_check' => 'passed', 'tests' => 'not_run'],
        'review' => [
            'diff' => '+ return true;',
            'citations' => [['id' => 'mary-tarpit', 'snippet' => 'Minimize accidental complexity.']],
        ],
    ]);

    expect($result['next_action'])->toBe('continue');

    Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->asks('next_action') && $prompt->contains('Assess this PHP diff.')
    );
});
