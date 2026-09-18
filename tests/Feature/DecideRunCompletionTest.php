<?php

use Sifrious\Molly\Actions\DecideRunCompletion;

function completeReview(): array
{
    $checks = [];
    foreach (range('A', 'G') as $code) {
        $checks[$code] = ['status' => 'clean', 'evidence' => 'No finding.'];
    }

    return ['checks' => $checks, 'findings' => []];
}

it('completes when required Pest and Tarpit checks pass', function () {
    config(['molly.verification' => ['pest' => 'required', 'tarpit' => 'required', 'parallel_join' => 'required']]);

    $decision = app(DecideRunCompletion::class)->handle([
        'mode' => 'serial',
        'verification' => ['status' => 'passed', 'tests' => 1, 'assertions' => 1],
        'review' => completeReview(),
    ], ['app/Greeting.php' => '<?php return "Hello";']);

    expect($decision['completed'])->toBeTrue()
        ->and($decision['blockers'])->toBe([])
        ->and($decision['outcomes']['pest'])->toBe(['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry'])
        ->and($decision['outcomes']['tarpit'])->toBe(['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry']);
});

it('blocks when a required verifier did not run', function () {
    config(['molly.verification' => ['pest' => 'required', 'tarpit' => 'required', 'parallel_join' => 'required']]);

    $decision = app(DecideRunCompletion::class)->handle([
        'mode' => 'serial',
        'review' => completeReview(),
    ], ['app/Greeting.php' => '<?php return "Hello";']);

    expect($decision['completed'])->toBeFalse()
        ->and($decision['outcomes']['pest']['state'])->toBe('NOT_RUN')
        ->and($decision['blockers'])->toContain('pest');
});

it('does not let a clean Tarpit review override failed Pest', function () {
    config(['molly.verification' => ['pest' => 'required', 'tarpit' => 'required', 'parallel_join' => 'required']]);

    $decision = app(DecideRunCompletion::class)->handle([
        'mode' => 'serial',
        'verification' => ['status' => 'failed'],
        'review' => completeReview(),
    ], ['app/Greeting.php' => '<?php return "Hello";']);

    expect($decision['completed'])->toBeFalse()
        ->and($decision['outcomes']['pest']['state'])->toBe('FAIL')
        ->and($decision['blockers'])->toContain('pest');
});

it('requires a valid parallel join when parallel mode is used', function () {
    config(['molly.verification' => ['pest' => 'required', 'tarpit' => 'required', 'parallel_join' => 'required']]);

    $decision = app(DecideRunCompletion::class)->handle([
        'mode' => 'parallel',
        'verification' => ['status' => 'passed', 'tests' => 1, 'assertions' => 1],
        'review' => completeReview(),
        'branches' => [
            ['kind' => 'verification', 'status' => 'passed', 'result_ref' => '/tmp/pest.json', 'finished_at' => '2026-09-17T19:00:00Z'],
            ['kind' => 'review', 'status' => 'passed', 'result_ref' => '/tmp/review.json', 'finished_at' => '2026-09-17T19:00:01Z'],
        ],
    ], ['app/Greeting.php' => '<?php return "Hello";']);

    expect($decision['completed'])->toBeTrue()
        ->and($decision['outcomes']['parallel_join'])->toBe(['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry']);
});

it('does not complete when Pest reports a pass without assertions', function () {
    config(['molly.verification' => ['pest' => 'required', 'tarpit' => 'required', 'parallel_join' => 'required']]);

    $decision = app(DecideRunCompletion::class)->handle([
        'mode' => 'serial',
        'verification' => ['status' => 'passed', 'tests' => 1, 'assertions' => 0],
        'review' => completeReview(),
    ], ['app/Greeting.php' => '<?php return \"Hello\";']);

    expect($decision['completed'])->toBeFalse()
        ->and($decision['outcomes']['pest']['state'])->toBe('FAIL')
        ->and($decision['blockers'])->toContain('pest');
});
