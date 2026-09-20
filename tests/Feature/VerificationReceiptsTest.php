<?php

use Illuminate\Support\Facades\Artisan;
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

it('reloads identical receipts after simulating a new process', function () {
    $junit = $this->workspace.'/pest.xml';
    File::put($junit, '<testsuite tests="1" assertions="1"><testcase assertions="1"/></testsuite>');
    $runId = (string) Str::uuid();
    $report = [
        'verification' => [
            'status' => 'passed',
            'tests' => 1,
            'assertions' => 1,
            'failures' => 0,
            'errors' => 0,
            'skipped' => 0,
            'junit' => $junit,
            'identified_required_test' => true,
        ],
        'review' => [
            'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']),
            'findings' => [],
        ],
        'verification_outcomes' => [
            'pest' => ['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry'],
            'tarpit' => ['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry'],
        ],
    ];

    $written = app(RecordVerificationReceipts::class)->handle($this->workspace, $runId, $report);
    $onDisk = collect(File::files($this->workspace.'/.molly/receipts/'.$runId))
        ->mapWithKeys(fn ($file) => [$file->getFilenameWithoutExtension() => trim(File::get($file->getPathname()))])
        ->all();

    // Simulate process restart: forget the container binding and construct a fresh recorder.
    app()->forgetInstance(RecordVerificationReceipts::class);
    $reloaded = app(RecordVerificationReceipts::class)->handle($this->workspace, $runId, [
        ...$report,
        'verification_outcomes' => [
            'pest' => ['state' => 'FAIL', 'policy' => 'required', 'failure_action' => 'retry'],
            'tarpit' => ['state' => 'FAIL', 'policy' => 'required', 'failure_action' => 'retry'],
        ],
    ]);

    expect($reloaded)->toHaveCount(2)
        ->and($reloaded[0]['evidence_digest'])->toBe($written[0]['evidence_digest'])
        ->and($reloaded[1]['evidence_digest'])->toBe($written[1]['evidence_digest'])
        ->and(trim(File::get($written[0]['path'])))->toBe($onDisk['pest'])
        ->and(trim(File::get($written[1]['path'])))->toBe($onDisk['tarpit']);
});

it('writes a new receipt directory for a new run id instead of rewriting the prior run', function () {
    $junit = $this->workspace.'/pest.xml';
    File::put($junit, '<testsuite tests="1" assertions="1"><testcase assertions="1"/></testsuite>');
    $firstRun = (string) Str::uuid();
    $secondRun = (string) Str::uuid();
    $base = [
        'verification' => [
            'status' => 'passed',
            'tests' => 1,
            'assertions' => 1,
            'junit' => $junit,
            'identified_required_test' => true,
        ],
        'review' => [
            'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']),
            'findings' => [],
        ],
        'verification_outcomes' => [
            'pest' => ['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry'],
            'tarpit' => ['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry'],
        ],
    ];

    $first = app(RecordVerificationReceipts::class)->handle($this->workspace, $firstRun, $base);
    $second = app(RecordVerificationReceipts::class)->handle($this->workspace, $secondRun, [
        ...$base,
        'verification_outcomes' => [
            'pest' => ['state' => 'FAIL', 'policy' => 'required', 'failure_action' => 'retry'],
            'tarpit' => ['state' => 'FAIL', 'policy' => 'required', 'failure_action' => 'retry'],
        ],
        'verification' => [
            'status' => 'failed',
            'tests' => 1,
            'assertions' => 0,
            'junit' => $junit,
        ],
    ]);

    expect($first[0]['path'])->not->toBe($second[0]['path'])
        ->and(dirname($first[0]['path']))->not->toBe(dirname($second[0]['path']))
        ->and(File::get($first[0]['path']))->toContain('"state":"PASS"')
        ->and(File::get($second[0]['path']))->toContain('"state":"FAIL"');
});

it('prints human-readable receipts through molly:receipt', function () {
    $junit = $this->workspace.'/pest.xml';
    File::put($junit, '<testsuite tests="1" assertions="1"><testcase assertions="1"/></testsuite>');
    $runId = (string) Str::uuid();
    app(RecordVerificationReceipts::class)->handle($this->workspace, $runId, [
        'verification' => [
            'status' => 'passed',
            'tests' => 1,
            'assertions' => 1,
            'junit' => $junit,
            'identified_required_test' => true,
        ],
        'review' => [
            'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']),
            'findings' => [],
        ],
        'verification_outcomes' => [
            'pest' => ['state' => 'PASS', 'policy' => 'required', 'failure_action' => 'retry'],
            'tarpit' => ['state' => 'PASS', 'policy' => 'advisory', 'failure_action' => 'warn'],
        ],
    ]);

    $this->artisan('molly:receipt', ['run' => $runId, '--workspace' => $this->workspace])
        ->expectsOutputToContain('pest — PASS')
        ->expectsOutputToContain('tarpit — PASS')
        ->expectsOutputToContain('evidence_digest:')
        ->assertSuccessful();

    $exit = Artisan::call('molly:receipt', ['run' => $runId, '--workspace' => $this->workspace, '--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($exit)->toBe(0)
        ->and($payload['run'])->toBe($runId)
        ->and($payload['receipts'])->toHaveCount(2);
});
