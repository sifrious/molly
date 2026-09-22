<?php

use Sifrious\Molly\Classification\ClassificationDecision;

it('stores bounded provenance scalars and arrays without secrets', function () {
    $decision = new ClassificationDecision(
        adapter: 'molly.fallback',
        action: 'inspect',
        confidence: 0.4,
        evidenceRefs: ['run:1'],
        deterministicFollowUp: 'keep_failed',
        provider: 'typesafe',
        model: 'jev-latest',
        laravelAiVersion: '0.11.2',
        question: 'Retry?',
        result: 'false',
        probability: 0.4,
        threshold: 0.8,
        fallbackReason: 'macro_missing',
    );

    expect($decision->toArray())->toMatchArray([
        'provider' => 'typesafe',
        'model' => 'jev-latest',
        'laravel_ai_version' => '0.11.2',
        'question' => 'Retry?',
        'result' => 'false',
        'probability' => 0.4,
        'threshold' => 0.8,
        'fallback_reason' => 'macro_missing',
    ])->and($decision->toArray())->not->toHaveKey('api_key');
});

it('keeps legacy constructor call sites working with null provenance', function () {
    $decision = new ClassificationDecision('molly.fallback', 'retry', null, [], 'keep_failed');

    expect($decision->provider)->toBeNull()
        ->and($decision->model)->toBeNull()
        ->and($decision->toArray()['fallback_reason'])->toBeNull();
});
