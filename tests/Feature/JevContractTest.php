<?php

use Sifrious\Molly\Actions\EvaluateWithTypeSafe;
use Sifrious\Molly\Actions\RecommendTaskNextStep;
use Sifrious\Molly\Classification\ChoiceClassification;
use Sifrious\Molly\Models\Task;

/**
 * The accepted Jev contract (docs/reference/configuration.md, "Jev states"),
 * proven through Molly's own classifier seam so it holds in every lane.
 */
function contractEvidence(): array
{
    return ['prompt' => 'Fix the flag.', 'verification' => ['status' => 'failed'], 'review' => ['findings' => []]];
}

function contractPlanning(): array
{
    return ['description' => 'Show the pending tasks.', 'answers' => [], 'guide_version' => 'v1', 'sources' => []];
}

function contractCommit(): array
{
    return ['prompt' => 'Assess this PHP diff.', 'verification' => ['diff_check' => 'passed', 'tests' => 'not_run'], 'review' => ['diff' => '+ return true;', 'citations' => []]];
}

it('performs no classification while the gate is closed', function () {
    $jev = fakeJev(jevChoice('retry'));
    config(['molly.jev.enabled' => false]);

    expect(app(EvaluateWithTypeSafe::class)->handle(contractEvidence()))
        ->toMatchArray(['status' => 'disabled', 'next_action' => null, 'reason' => 'jev_disabled', 'confidence' => null, 'answers' => [], 'provider' => 'typesafe'])
        ->and(app(EvaluateWithTypeSafe::class)->planning(contractPlanning()))->toMatchArray(['status' => 'disabled', 'focus' => null, 'reason' => 'jev_disabled'])
        ->and(app(EvaluateWithTypeSafe::class)->commit(contractCommit()))->toMatchArray(['status' => 'disabled', 'next_action' => null, 'reason' => 'jev_disabled'])
        ->and($jev->requests)->toBe([]);
});

it('reports enabled-but-unavailable Jev explicitly instead of as success', function () {
    $jev = fakeJev(jevChoice('retry'), available: false);

    expect(app(EvaluateWithTypeSafe::class)->handle(contractEvidence()))
        ->toMatchArray(['status' => 'unavailable', 'next_action' => null, 'reason' => 'capability_missing', 'confidence' => null, 'answers' => []])
        ->and(app(EvaluateWithTypeSafe::class)->planning(contractPlanning()))->toMatchArray(['status' => 'unavailable', 'focus' => null, 'reason' => 'capability_missing'])
        ->and(app(EvaluateWithTypeSafe::class)->commit(contractCommit()))->toMatchArray(['status' => 'unavailable', 'next_action' => null, 'reason' => 'capability_missing'])
        ->and($jev->requests)->toBe([]);
});

it('rejects a missing credential before classification', function () {
    $jev = fakeJev(jevChoice('retry'));
    config(['ai.providers.typesafe.key' => null]);

    expect(app(EvaluateWithTypeSafe::class)->handle(contractEvidence()))
        ->toMatchArray(['status' => 'needs_review', 'next_action' => 'needs_review', 'reason' => 'invalid_config'])
        ->and($jev->requests)->toBe([]);
});

it('rejects invalid Jev policy before classification', function (string $key, mixed $value) {
    $jev = fakeJev(jevChoice('retry'));
    config(["molly.jev.$key" => $value]);

    expect(app(EvaluateWithTypeSafe::class)->handle(contractEvidence()))
        ->toMatchArray(['status' => 'needs_review', 'next_action' => 'needs_review', 'reason' => 'invalid_config'])
        ->and($jev->requests)->toBe([]);
})->with([
    ['model', ''], ['model', str_repeat('m', 129)], ['model', null],
    ['timeout', 0], ['timeout', 121], ['timeout', '30'],
    ['confidence_threshold', 1.1], ['confidence_threshold', -0.1], ['confidence_threshold', '0.8'],
    ['instructions', ''], ['instructions', str_repeat('i', 4097)],
]);

it('rejects incomplete or oversized evidence before classification', function (array $evidence) {
    $jev = fakeJev(jevChoice('retry'));

    expect(app(EvaluateWithTypeSafe::class)->handle($evidence))
        ->toMatchArray(['status' => 'needs_review', 'next_action' => 'needs_review', 'reason' => 'invalid_evidence'])
        ->and($jev->requests)->toBe([]);
})->with([
    'empty' => [[]],
    'missing review' => [['prompt' => 'Fix.', 'verification' => []]],
    'oversized' => [['prompt' => str_repeat('x', 32769), 'verification' => [], 'review' => []]],
]);

it('maps each allowed choice and sends only the bounded state', function (string $choice) {
    $jev = fakeJev(jevChoice($choice));

    $result = app(EvaluateWithTypeSafe::class)->handle(contractEvidence());

    expect($result)->toMatchArray(['status' => 'evaluated', 'next_action' => $choice, 'reason' => 'evaluated', 'confidence' => 0.9, 'provider' => 'typesafe', 'model' => 'jev-latest'])
        ->and($result['answers']['next_action'])->toMatchArray(['type' => 'choice', 'choice' => $choice, 'confidence' => 0.9])
        ->and($jev->requests)->toHaveCount(1)
        ->and($jev->requests[0]['question'])->toBe('next_action')
        ->and(array_keys($jev->requests[0]['criteria']))->toBe(['continue', 'retry', 'stop', 'needs_review'])
        ->and($jev->requests[0]['instructions'])->toBe(config('molly.jev.instructions'))
        ->and($jev->requests[0]['model'])->toBe('jev-latest')
        ->and($jev->requests[0]['timeout'])->toBe(30)
        ->and($jev->state())->toBe(contractEvidence());
})->with(['continue', 'retry', 'stop', 'needs_review']);

it('asks the planning and commit questions with their fixed rubrics', function () {
    $jev = fakeJev(jevChoice('state', ['outcome', 'state', 'laravel', 'boundaries', 'verification']));
    expect(app(EvaluateWithTypeSafe::class)->planning(contractPlanning()))->toMatchArray(['status' => 'evaluated', 'focus' => 'state']);
    expect(array_keys($jev->requests[0]['criteria']))->toBe(['outcome', 'state', 'laravel', 'boundaries', 'verification'])
        ->and($jev->requests[0]['question'])->toBe('focus');

    $jev = fakeJev(jevChoice('continue'));
    expect(app(EvaluateWithTypeSafe::class)->commit(contractCommit()))->toMatchArray(['status' => 'evaluated', 'next_action' => 'continue']);
    expect($jev->requests[0]['question'])->toBe('next_action')
        ->and($jev->requests[0]['instructions'])->toContain('Never claim tests ran.');
});

it('treats malformed answers as invalid without applying them', function (?ChoiceClassification $answer) {
    $jev = fakeJev($answer);

    expect(app(EvaluateWithTypeSafe::class)->handle(contractEvidence()))
        ->toMatchArray(['status' => 'needs_review', 'next_action' => 'needs_review', 'reason' => 'invalid_answer', 'confidence' => null, 'answers' => []])
        ->and($jev->requests)->toHaveCount(1);
})->with([
    'no answer' => [null],
    'unknown option' => [new ChoiceClassification('maybe', ['continue' => 0, 'retry' => 0, 'stop' => 0, 'needs_review' => 1], 0.9)],
    'missing option' => [new ChoiceClassification('retry', ['continue' => 0, 'retry' => 1, 'stop' => 0], 0.9)],
    'extra option' => [new ChoiceClassification('retry', ['continue' => 0, 'retry' => 1, 'stop' => 0, 'needs_review' => 0, 'skip' => 0], 0.9)],
    'non-numeric probability' => [new ChoiceClassification('retry', ['continue' => 0, 'retry' => 'high', 'stop' => 0, 'needs_review' => 0], 0.9)],
    'out of range probability' => [new ChoiceClassification('retry', ['continue' => 0, 'retry' => 1.5, 'stop' => -0.5, 'needs_review' => 0], 0.9)],
    'distribution does not sum to one' => [new ChoiceClassification('retry', ['continue' => 0.5, 'retry' => 0.9, 'stop' => 0, 'needs_review' => 0], 0.9)],
    'choice is not the maximum' => [new ChoiceClassification('retry', ['continue' => 0.7, 'retry' => 0.3, 'stop' => 0, 'needs_review' => 0], 0.9)],
    'missing confidence' => [new ChoiceClassification('retry', ['continue' => 0, 'retry' => 1, 'stop' => 0, 'needs_review' => 0], null)],
    'confidence above one' => [new ChoiceClassification('retry', ['continue' => 0, 'retry' => 1, 'stop' => 0, 'needs_review' => 0], 1.5)],
]);

it('keeps low confidence as review and accepts confidence exactly at the threshold', function (float $confidence, string $status, string $reason, string $action) {
    fakeJev(jevChoice('retry', confidence: $confidence));

    expect(app(EvaluateWithTypeSafe::class)->handle(contractEvidence()))
        ->toMatchArray(['status' => $status, 'next_action' => $action, 'reason' => $reason, 'confidence' => $confidence]);
})->with([
    'below' => [0.79, 'needs_review', 'low_confidence', 'needs_review'],
    'exactly at' => [0.8, 'evaluated', 'evaluated', 'retry'],
    'above' => [0.81, 'evaluated', 'evaluated', 'retry'],
]);

it('reports provider failures without retaining the failure payload', function () {
    $jev = fakeJev(new RuntimeException('secret provider payload token=abc'));

    $result = app(EvaluateWithTypeSafe::class)->handle(contractEvidence());

    expect($result)->toMatchArray(['status' => 'needs_review', 'next_action' => 'needs_review', 'reason' => 'provider_error', 'confidence' => null, 'answers' => []])
        ->and(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('secret provider payload', 'token=abc')
        ->and($jev->requests)->toHaveCount(1);
});

it('keeps a failed deterministic gate authoritative over an evaluated continue', function () {
    fakeJev(jevChoice('continue', confidence: 0.99));
    $task = Task::create(['nickname' => 'flag', 'prompt' => 'Fix the flag.', 'workspace' => sys_get_temp_dir(), 'paths' => ['app/Flag.php'], 'test_path' => 'tests/FlagTest.php', 'status' => 'failed']);
    $run = $task->runs()->create(['prompt' => $task->prompt, 'workspace' => $task->workspace, 'status' => 'failed', 'report' => ['verification' => ['status' => 'failed', 'tests' => 1, 'failures' => 1], 'review' => ['findings' => []]]]);

    $advice = app(RecommendTaskNextStep::class)->handle($task->id);

    expect($advice['status'])->toBe('evaluated')
        ->and($advice['next_action'])->toBe('inspect')
        ->and($advice['reason'])->toContain('does not establish success')
        ->and($task->fresh()->status)->toBe('failed')
        ->and($run->fresh()->status)->toBe('failed')
        ->and($run->fresh()->report['verification']['status'])->toBe('failed');
});
