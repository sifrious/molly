<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\RecordVerificationReceipts;
use Sifrious\Molly\Contracts\VerificationOutcome;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-receipts-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

it('writes immutable verification receipts with evidence digests', function () {
    $junit = $this->workspace.'/pest.xml';
    File::put($junit, '<testsuite tests="1" assertions="1"><testcase assertions="1"/></testsuite>');
    $runId = (string) Str::uuid();

    $receipts = app(RecordVerificationReceipts::class)->handle($this->workspace, $runId, [
        'verification' => [
            'status' => 'passed',
            'tests' => 1,
            'assertions' => 1,
            'failures' => 0,
            'errors' => 0,
            'skipped' => 0,
            'junit' => $junit,
        ],
        'review' => [
            'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']),
            'findings' => [],
        ],
        'verification_outcomes' => [
            'pest' => ['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry'],
            'tarpit' => ['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry'],
        ],
    ]);

    expect($receipts)->toHaveCount(2)
        ->and($receipts[0]['schema'])->toBe(VerificationOutcome::SCHEMA)
        ->and($receipts[0]['verifier'])->toBe('pest')
        ->and($receipts[0]['another_attempt_permitted'])->toBeFalse()
        ->and(is_file($receipts[0]['path']))->toBeTrue();

    $first = File::get($receipts[0]['path']);
    $again = app(RecordVerificationReceipts::class)->handle($this->workspace, $runId, [
        'verification' => ['status' => 'failed', 'tests' => 0, 'assertions' => 0, 'junit' => $junit],
        'review' => ['checks' => [], 'findings' => []],
        'verification_outcomes' => [
            'pest' => ['state' => 'FAIL', 'policy' => 'required', 'failure_action' => 'retry'],
            'tarpit' => ['state' => 'FAIL', 'policy' => 'required', 'failure_action' => 'retry'],
        ],
    ]);

    expect(File::get($receipts[0]['path']))->toBe($first)
        ->and($again[0]['state'])->toBe('PASS')
        ->and($again[0]['evidence_digest'])->toBe($receipts[0]['evidence_digest']);
});
