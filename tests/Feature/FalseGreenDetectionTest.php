<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\DecideRunCompletion;
use Sifrious\Molly\Actions\DetectFalseGreen;
use Sifrious\Molly\Actions\RecordVerificationReceipts;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Verification\FalseGreenVerifier;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-fg-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return "Hello";');
    writeProtectedTest($this->workspace);
    config([
        'molly.false_green.enabled' => true,
        'molly.false_green.max_mutations' => 2,
        'molly.false_green.timeout_seconds' => 30,
        'molly.verification.false_green' => 'required',
        'molly.verification_actions.false_green' => 'fail',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('reports a weak test that stays green after protected behavior is broken', function () {
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn([
        'status' => 'passed',
        'tests' => 1,
        'assertions' => 1,
        'failures' => 0,
        'errors' => 0,
        'skipped' => 0,
        'identified_required_test' => true,
    ]);

    $before = File::get($this->workspace.'/app/Greeting.php');
    $result = app(DetectFalseGreen::class)->handle(
        $this->workspace,
        'tests/GreetingTest.php',
        ['app/Greeting.php'],
        $this->workspace.'/.molly/fg',
    );

    expect($result['status'])->toBe('false_green')
        ->and($result['state'])->toBe('FAIL')
        ->and($result['conclusion'])->toBe('weak_test')
        ->and($result['probes'][0]['outcome'])->toBe('stayed_green')
        ->and($result['probes'][0]['mutation'])->toBe('replace_php_body_with_throwing_stub')
        ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe($before);
});

it('reports a meaningful test that fails when protected behavior is broken', function () {
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn([
        'status' => 'failed',
        'tests' => 1,
        'assertions' => 1,
        'failures' => 1,
        'errors' => 0,
        'skipped' => 0,
        'reason' => 'tests_failed',
    ]);

    $before = File::get($this->workspace.'/app/Greeting.php');
    $result = app(DetectFalseGreen::class)->handle(
        $this->workspace,
        'tests/GreetingTest.php',
        ['app/Greeting.php'],
        $this->workspace.'/.molly/fg',
    );

    expect($result['status'])->toBe('meaningful')
        ->and($result['state'])->toBe('PASS')
        ->and($result['conclusion'])->toBe('test_failed_under_mutation')
        ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe($before);
});

it('restores the canonical workspace even when the probe errors', function () {
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andThrow(new RuntimeException('boom'));
    $before = File::get($this->workspace.'/app/Greeting.php');

    $result = app(DetectFalseGreen::class)->handle(
        $this->workspace,
        'tests/GreetingTest.php',
        ['app/Greeting.php'],
        $this->workspace.'/.molly/fg',
    );

    expect($result['status'])->toBe('inconclusive')
        ->and($result['state'])->toBe('REVIEW_REQUIRED')
        ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe($before);
});

it('returns NOT_RUN when disabled and does not mutate files', function () {
    config(['molly.false_green.enabled' => false]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->never();
    $before = File::get($this->workspace.'/app/Greeting.php');

    $result = app(DetectFalseGreen::class)->handle(
        $this->workspace,
        'tests/GreetingTest.php',
        ['app/Greeting.php'],
        $this->workspace.'/.molly/fg',
    );

    expect($result['status'])->toBe('not_run')
        ->and($result['state'])->toBe('NOT_RUN')
        ->and(File::get($this->workspace.'/app/Greeting.php'))->toBe($before);
});

it('bounds mutation count from config', function () {
    config(['molly.false_green.max_mutations' => 1]);
    File::put($this->workspace.'/app/Other.php', '<?php return 1;');
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn([
        'status' => 'failed', 'tests' => 1, 'assertions' => 1, 'failures' => 1, 'errors' => 0, 'skipped' => 0,
    ]);

    $result = app(DetectFalseGreen::class)->handle(
        $this->workspace,
        'tests/GreetingTest.php',
        ['app/Greeting.php', 'app/Other.php'],
        $this->workspace.'/.molly/fg',
    );

    expect($result['budgets']['mutations_attempted'])->toBe(1)
        ->and($result['budgets']['max_mutations'])->toBe(1)
        ->and($result['probes'])->toHaveCount(1);
});

it('treats inconclusive false-green as a completion blocker when required', function () {
    $decision = app(DecideRunCompletion::class)->handle([
        'verification' => [
            'status' => 'passed',
            'tests' => 1,
            'assertions' => 1,
            'identified_required_test' => true,
        ],
        'review' => [
            'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'ok']),
            'findings' => [],
        ],
        'false_green' => [
            'status' => 'inconclusive',
            'state' => 'REVIEW_REQUIRED',
            'conclusion' => 'timeout',
        ],
    ], ['app/Greeting.php' => '<?php return "Hello";']);

    expect($decision['completed'])->toBeFalse()
        ->and($decision['blockers'])->toContain('false_green')
        ->and($decision['outcomes']['false_green']['state'])->toBe('REVIEW_REQUIRED');
});

it('records false-green probes on verification receipts', function () {
    $runId = (string) Str::uuid();
    $receipts = app(RecordVerificationReceipts::class)->handle($this->workspace, $runId, [
        'verification' => [
            'status' => 'passed',
            'tests' => 1,
            'assertions' => 1,
            'identified_required_test' => true,
        ],
        'review' => [
            'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'ok']),
            'findings' => [],
        ],
        'false_green' => [
            'status' => 'false_green',
            'state' => 'FAIL',
            'conclusion' => 'weak_test',
            'test_path' => 'tests/GreetingTest.php',
            'probes' => [['path' => 'app/Greeting.php', 'outcome' => 'stayed_green']],
            'reason' => 'stayed green',
            'budgets' => ['max_mutations' => 1, 'mutations_attempted' => 1],
        ],
        'verification_outcomes' => [
            'pest' => ['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry'],
            'tarpit' => ['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry'],
            'false_green' => ['state' => 'FAIL', 'policy' => 'required', 'failure_action' => 'fail'],
        ],
    ]);

    $names = array_column($receipts, 'verifier');
    expect($names)->toContain('false_green')
        ->and(File::get($this->workspace.'/.molly/receipts/'.$runId.'/false_green.json'))->toContain('"state":"FAIL"');
});

it('binds DetectFalseGreen as the FalseGreenVerifier', function () {
    expect(app(FalseGreenVerifier::class))->toBeInstanceOf(DetectFalseGreen::class);
});
