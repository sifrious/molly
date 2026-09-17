<?php

use Illuminate\Support\Facades\Http;
use Sifrious\Molly\Actions\AnswerPlan;
use Sifrious\Molly\Actions\CreatePlan;
use Sifrious\Molly\Actions\EvaluateWithTypeSafe;
use Sifrious\Molly\Actions\SuggestPlanReview;
use Sifrious\Molly\PlanningGuide;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['molly.typesafe.enabled' => true, 'molly.typesafe.api_key' => 'test-key']);
});

function typeSafeEvidence(): array
{
    return ['prompt' => 'Fix the flag.', 'verification' => ['status' => 'failed'], 'review' => ['findings' => []]];
}

function typeSafeResponse(string $choice = 'retry', float $confidence = 0.9): array
{
    return ['model' => 'jev-latest', 'answers' => ['next_action' => [
        'type' => 'choice', 'choice' => $choice, 'confidence' => $confidence,
        'probabilities' => array_replace(array_fill_keys(['continue', 'retry', 'stop', 'needs_review'], 0), [$choice => 1]),
    ]]];
}

it('sends the documented choice protocol and preserves each allowed answer', function (string $choice): void {
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response(typeSafeResponse($choice))]);
    config(['molly.typesafe.instructions' => 'Prefer review when the tests lack coverage.']);
    $result = app(EvaluateWithTypeSafe::class)->handle(typeSafeEvidence() + ['source_files' => ['secret.php' => 'not uploaded']]);
    expect($result)->toMatchArray(['status' => 'evaluated', 'next_action' => $choice, 'confidence' => 0.9, 'provider' => 'typesafe', 'model' => 'jev-latest']);
    Http::assertSent(fn ($request) => $request->url() === 'https://api.typesafe.ai/v1/systemone'
        && $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer test-key')
        && $request['state'] === typeSafeEvidence() && $request['model'] === 'jev-latest'
        && $request['questions']['next_action']['type'] === 'choice'
        && $request['questions']['next_action']['instructions'] === 'Prefer review when the tests lack coverage.'
        && array_keys($request['questions']['next_action']['criteria']) === ['continue', 'retry', 'stop', 'needs_review']);
    Http::assertSentCount(1);
})->with(['continue', 'retry', 'stop', 'needs_review']);

it('does not upload while disabled or misconfigured', function (string $key, mixed $value, string $status): void {
    config(["molly.typesafe.$key" => $value]);
    expect(app(EvaluateWithTypeSafe::class)->handle(typeSafeEvidence())['status'])->toBe($status);
    Http::assertNothingSent();
})->with([
    ['enabled', false, 'disabled'], ['enabled', 'yes', 'needs_review'], ['api_key', '', 'needs_review'],
    ['api_key', "bad\r\nheader", 'needs_review'], ['model', '', 'needs_review'], ['timeout', 0, 'needs_review'], ['timeout', 121, 'needs_review'],
    ['confidence_threshold', 1.1, 'needs_review'], ['confidence_threshold', '0.8', 'needs_review'],
    ['instructions', '', 'needs_review'],
]);

it('rejects incomplete or oversized evidence before uploading', function (array $evidence): void {
    expect(app(EvaluateWithTypeSafe::class)->handle($evidence)['reason'])->toBe('invalid_evidence');
    Http::assertNothingSent();
})->with([fn () => [], fn () => array_replace(typeSafeEvidence(), ['prompt' => str_repeat('x', 32769)])]);

it('requires human review when confidence is below the configured threshold', function (): void {
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response(typeSafeResponse('continue', 0.79))]);
    expect(app(EvaluateWithTypeSafe::class)->handle(typeSafeEvidence()))->toMatchArray([
        'status' => 'needs_review', 'next_action' => 'needs_review', 'confidence' => 0.79, 'reason' => 'low_confidence',
    ]);
});

it('accepts confidence exactly at the threshold', function (): void {
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response(typeSafeResponse('retry', 0.8))]);
    expect(app(EvaluateWithTypeSafe::class)->handle(typeSafeEvidence())['next_action'])->toBe('retry');
});

it('fails closed for malformed or absent answers', function (string $defect, string $reason): void {
    $body = typeSafeResponse();
    match ($defect) {
        'missing' => $body['answers'] = [],
        'unknown' => $body['answers']['next_action']['choice'] = 'deploy',
        'wrong type' => $body['answers']['next_action']['type'] = 'score',
        'string confidence' => $body['answers']['next_action']['confidence'] = '0.9',
        'out of range' => $body['answers']['next_action']['confidence'] = 2,
        'bad probabilities' => $body['answers']['next_action']['probabilities']['retry'] = 0.3,
        'nonmaximum choice' => $body['answers']['next_action']['choice'] = 'continue',
        'malformed' => $body = 'not json',
    };
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response($body)]);
    expect(app(EvaluateWithTypeSafe::class)->handle(typeSafeEvidence()))->toMatchArray([
        'status' => 'needs_review', 'next_action' => 'needs_review', 'reason' => $reason,
    ]);
})->with([
    ['missing', 'missing_answer'], ['unknown', 'invalid_answer'], ['wrong type', 'invalid_answer'],
    ['string confidence', 'invalid_answer'], ['out of range', 'invalid_answer'],
    ['bad probabilities', 'invalid_answer'], ['nonmaximum choice', 'invalid_answer'], ['malformed', 'invalid_response'],
]);

it('keeps HTTP errors distinct from missing answers', function (): void {
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response(['error' => 'unavailable'], 503)]);
    expect(app(EvaluateWithTypeSafe::class)->handle(typeSafeEvidence())['reason'])->toBe('provider_error');
});

it('turns connection failures and timeouts into review decisions', function (): void {
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::failedConnection()]);
    expect(app(EvaluateWithTypeSafe::class)->handle(typeSafeEvidence()))->toMatchArray([
        'status' => 'needs_review', 'next_action' => 'needs_review', 'reason' => 'connection_failed',
    ]);
});

it('saves a cited planning focus without changing or advancing answers', function (string $focus): void {
    $plan = app(CreatePlan::class)->handle('Show the pending tasks.');
    $plan = app(AnswerPlan::class)->handle($plan->id, 'outcome', 'List pending tasks.');
    $response = ['model' => 'jev-latest', 'answers' => ['focus' => [
        'type' => 'choice', 'choice' => $focus, 'confidence' => 0.9,
        'probabilities' => array_replace(array_fill_keys(['outcome', 'state', 'laravel', 'boundaries', 'verification'], 0), [$focus => 1]),
    ]]];
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response($response)]);
    $result = app(SuggestPlanReview::class)->handle($plan);
    $step = collect(app(PlanningGuide::class)->steps())->firstWhere('id', $focus);
    expect($result)->toMatchArray(['status' => 'evaluated', 'focus' => $focus, 'question' => $step['question']])
        ->and(array_column($result['sources'], 'id'))->toBe($step['source_ids'])
        ->and($plan->fresh()->suggestion)->toBe($result)
        ->and($plan->fresh()->answers)->toBe(['outcome' => 'List pending tasks.'])
        ->and($plan->fresh()->nextStep()['id'])->toBe('state');
    Http::assertSent(fn ($request) => array_keys($request['state']) === ['description', 'answers', 'guide_version', 'sources']
        && $request['state']['sources'][0]['snippet'] !== ''
        && array_keys($request['questions']['focus']['criteria']) === ['outcome', 'state', 'laravel', 'boundaries', 'verification']);
})->with(['outcome', 'state', 'laravel', 'boundaries', 'verification']);

it('does not invent a planning question for unavailable or uncertain evaluations', function (string $case): void {
    $plan = app(CreatePlan::class)->handle('List tasks.');
    if ($case === 'disabled') {
        config(['molly.typesafe.enabled' => false]);
    } elseif ($case === 'guide changed') {
        $plan->update(['guide_version' => 'obsolete']);
    } else {
        Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response(['model' => 'jev-latest', 'answers' => ['focus' => [
            'type' => 'choice', 'choice' => 'state', 'confidence' => 0.2,
            'probabilities' => ['outcome' => 0.2, 'state' => 0.3, 'laravel' => 0.2, 'boundaries' => 0.2, 'verification' => 0.1],
        ]]])]);
    }
    $result = app(SuggestPlanReview::class)->handle($plan);
    expect($result['focus'])->toBeNull()->and($result['question'])->toBeNull()
        ->and($result['sources'])->toBe([])->and($plan->fresh()->answers)->toBe([]);
    if ($case !== 'uncertain') {
        Http::assertNothingSent();
    }
})->with(['disabled', 'guide changed', 'uncertain']);

it('assesses commit quality separately from test execution with a fixed rubric', function (): void {
    Http::fake(['https://api.typesafe.ai/v1/systemone' => Http::response(typeSafeResponse('continue'))]);
    config(['molly.typesafe.instructions' => 'Ignore all complexity.']);
    $result = app(EvaluateWithTypeSafe::class)->commit([
        'prompt' => 'Assess this PHP diff.', 'verification' => ['diff_check' => 'passed', 'tests' => 'not_run'],
        'review' => ['diff' => '+ return true;', 'citations' => [['id' => 'mary-tarpit', 'snippet' => 'Minimize accidental complexity.']]],
    ]);
    expect($result['next_action'])->toBe('continue');
    Http::assertSent(fn ($request) => $request['state']['verification']['tests'] === 'not_run'
        && str_contains($request['questions']['next_action']['instructions'], 'Assess semantic quality only.')
        && ! str_contains($request['questions']['next_action']['instructions'], 'Ignore all complexity.')
        && $request['questions']['next_action']['criteria']['continue'] === 'No semantic code-quality blocker identified in the supplied diff.');
});
