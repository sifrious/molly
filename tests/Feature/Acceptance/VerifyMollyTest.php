<?php

use Illuminate\Support\Facades\File;
use Sifrious\Molly\Acceptance\CheckOutcome;
use Sifrious\Molly\Acceptance\M04CheckCatalog;
use Sifrious\Molly\Acceptance\MacOsVerifierPermissionInspector;
use Sifrious\Molly\Acceptance\MacOsVerifierPermissionRequester;
use Sifrious\Molly\Acceptance\PackageRoot;
use Sifrious\Molly\Acceptance\PermissionKind;
use Sifrious\Molly\Acceptance\PermissionState;
use Sifrious\Molly\Acceptance\VerificationContext;
use Sifrious\Molly\Acceptance\VerificationMode;
use Sifrious\Molly\Acceptance\VerifierPermissionInspector;
use Sifrious\Molly\Acceptance\VerifierPermissionSnapshot;
use Sifrious\Molly\Acceptance\VerifierProcessIdentity;
use Sifrious\Molly\Actions\VerifyMolly;
use Sifrious\Molly\Mcp\MollyServer;
use Sifrious\Molly\Mcp\MollyVerify;
use Sifrious\Molly\Tests\Support\FixedPermissionInspector;
use Sifrious\Molly\Tests\Support\SequencePermissionInspector;
use Sifrious\Molly\Tests\Support\StubPermissionPrompt;

function verificationSha(): string
{
    return str_repeat('ab', 20);
}

beforeEach(function () {
    $this->verificationDirectories = [];
});

function verificationDirectory(): string
{
    $directory = sys_get_temp_dir().'/molly-verify-'.bin2hex(random_bytes(4));
    mkdir($directory, 0700, true);
    $directories = test()->verificationDirectories;
    $directories[] = $directory;
    test()->verificationDirectories = $directories;

    return $directory;
}

function bindPermissions(PermissionState $screen, PermissionState $accessibility, string $platform = 'Darwin'): void
{
    app()->instance(VerifierPermissionInspector::class, new FixedPermissionInspector(permissionSnapshot($screen, $accessibility, $platform)));
}

function permissionSnapshot(PermissionState $screen, PermissionState $accessibility, string $platform = 'Darwin'): VerifierPermissionSnapshot
{
    return new VerifierPermissionSnapshot(
        $screen,
        $accessibility,
        VerifierProcessIdentity::capture(),
        $platform,
        $platform === 'Darwin' ? '15.6' : null,
        'verification-test',
        new DateTimeImmutable('now', new DateTimeZone('UTC')),
    );
}

function programmedContext(string $directory, array $replace = []): VerificationContext
{
    $observations = [];
    $native = [];
    foreach ((new M04CheckCatalog)->checks() as $check) {
        if ($check->requiredPermissions !== []) {
            $observations[$check->id] = [
                'disposition' => 'needs_native',
                'reason_code' => 'NATIVE_UI_REQUIRED',
                'message' => $check->requirement.' still needs a native Bloom observation.',
            ];
            $native[$check->id] = [
                'disposition' => 'complete',
                'outcome' => CheckOutcome::Pass->value,
                'reason_code' => 'NATIVE_OBSERVED',
                'message' => $check->id.' native observation passed.',
            ];
        } else {
            $observations[$check->id] = [
                'disposition' => 'complete',
                'outcome' => CheckOutcome::Pass->value,
                'reason_code' => 'PROGRAMMATIC_PASS',
                'message' => $check->id.' passed from shared state.',
            ];
        }
    }

    return new VerificationContext(
        verificationSha(),
        '',
        PackageRoot::path(),
        $directory,
        VerificationMode::Default,
        null,
        array_replace($observations, $replace['observations'] ?? []),
        array_replace($native, $replace['native'] ?? []),
        $replace['harnessBroken'] ?? [],
        $replace['sharedState'] ?? [],
    );
}

function outcomeOf(array $document, string $id): string
{
    foreach ($document['checks'] as $check) {
        if ($check['check_id'] === $id) {
            return $check['outcome'];
        }
    }

    throw new RuntimeException('Missing '.$id);
}

function attemptPath(string $directory, array $document, string $id, int $attempt): string
{
    return $directory.'/runs/'.$document['run_id'].'/checks/'.$id.'/attempt-'.$attempt.'.json';
}

afterEach(function () {
    foreach ($this->verificationDirectories ?? [] as $directory) {
        File::deleteDirectory($directory);
    }
});

it('runs native checks when both permissions are granted', function () {
    bindPermissions(PermissionState::Granted, PermissionState::Granted);
    $directory = verificationDirectory();

    $document = app(VerifyMolly::class)->verify(
        VerificationMode::Default,
        $directory,
        verificationSha(),
        programmedContext($directory),
    );

    expect($document['exit_code'])->toBe(0)
        ->and($document['permission_granted_by_cli'])->toBeFalse()
        ->and($document['acceptance_program_complete'])->toBeFalse()
        ->and($document['summary'])->toMatchArray([
            'product_failures' => 0,
            'harness_failures' => 0,
            'permission_blocks' => 0,
            'passes' => 16,
            'release_complete' => true,
        ])
        ->and($document['native_checks_run'])->toBe(['M04.1', 'M04.2', 'M04.3', 'M04.10', 'M04.12', 'M04.16'])
        ->and($document['stages'][0]['release_complete'])->toBeTrue();
});

it('blocks screen recording checks and still runs accessibility and programmatic checks', function () {
    bindPermissions(PermissionState::Denied, PermissionState::Granted);
    $directory = verificationDirectory();

    $document = app(VerifyMolly::class)->verify(
        VerificationMode::Default,
        $directory,
        verificationSha(),
        programmedContext($directory),
    );

    expect($document['native_checks_run'])->toBe(['M04.2', 'M04.3', 'M04.10'])
        ->and(outcomeOf($document, 'M04.1'))->toBe('BLOCKED_VERIFIER_PERMISSION')
        ->and(outcomeOf($document, 'M04.12'))->toBe('BLOCKED_VERIFIER_PERMISSION')
        ->and(outcomeOf($document, 'M04.16'))->toBe('BLOCKED_VERIFIER_PERMISSION')
        ->and(outcomeOf($document, 'M04.2'))->toBe('PASS')
        ->and(outcomeOf($document, 'M04.4'))->toBe('PASS')
        ->and($document['summary']['product_failures'])->toBe(0)
        ->and($document['stages'][0])->toMatchArray([
            'stage' => 'M04',
            'product_failures' => 0,
            'harness_failures' => 0,
            'permission_blocks' => 3,
            'passes' => 13,
            'release_complete' => false,
        ])
        ->and($document['exit_code'])->toBe(3)
        ->and($document['checks'][0]['acceptance_record_outcome'])->toBe('BLOCKED');
});

it('blocks accessibility checks and still runs screen recording checks', function () {
    bindPermissions(PermissionState::Granted, PermissionState::NotDetermined);
    $directory = verificationDirectory();

    $document = app(VerifyMolly::class)->verify(
        VerificationMode::Default,
        $directory,
        verificationSha(),
        programmedContext($directory),
    );

    expect($document['native_checks_run'])->toBe(['M04.1', 'M04.12', 'M04.16'])
        ->and(outcomeOf($document, 'M04.2'))->toBe('BLOCKED_VERIFIER_PERMISSION')
        ->and(outcomeOf($document, 'M04.3'))->toBe('BLOCKED_VERIFIER_PERMISSION')
        ->and(outcomeOf($document, 'M04.10'))->toBe('BLOCKED_VERIFIER_PERMISSION')
        ->and(outcomeOf($document, 'M04.1'))->toBe('PASS')
        ->and(outcomeOf($document, 'M04.9'))->toBe('PASS')
        ->and($document['summary']['product_failures'])->toBe(0);
});

it('keeps running programmatic checks when both permissions are missing', function () {
    bindPermissions(PermissionState::NotDetermined, PermissionState::Denied);
    $directory = verificationDirectory();
    $context = programmedContext($directory);

    $default = app(VerifyMolly::class)->verify(VerificationMode::Default, $directory, verificationSha(), $context);
    $permissionlessDirectory = verificationDirectory();
    $permissionless = app(VerifyMolly::class)->verify(
        VerificationMode::Permissionless,
        $permissionlessDirectory,
        verificationSha(),
        programmedContext($permissionlessDirectory),
    );

    expect($default['summary'])->toMatchArray(['product_failures' => 0, 'permission_blocks' => 6, 'passes' => 10])
        ->and($default['exit_code'])->toBe(3)
        ->and($default['permission_granted_by_cli'])->toBeFalse()
        ->and($permissionless['exit_code'])->toBe(0)
        ->and($permissionless['summary']['permission_blocks'])->toBe(6)
        ->and($permissionless['release_complete'])->toBeFalse()
        ->and(outcomeOf($permissionless, 'M04.4'))->toBe('PASS')
        ->and(outcomeOf($permissionless, 'M04.2'))->toBe('BLOCKED_VERIFIER_PERMISSION');
});

it('reruns only the checks a verifier permission blocked', function () {
    $directory = verificationDirectory();
    bindPermissions(PermissionState::Denied, PermissionState::Granted);
    $first = app(VerifyMolly::class)->verify(
        VerificationMode::Default,
        $directory,
        verificationSha(),
        programmedContext($directory),
    );
    $blockedAttempt = file_get_contents(attemptPath($directory, $first, 'M04.1', 1));
    $passedAttempt = file_get_contents(attemptPath($directory, $first, 'M04.2', 1));
    $preflight = file_get_contents($directory.'/runs/'.$first['run_id'].'/preflight-attempt-1.json');

    bindPermissions(PermissionState::Granted, PermissionState::Granted);
    $retry = app(VerifyMolly::class)->verify(
        VerificationMode::RetryNativeUi,
        $directory,
        verificationSha(),
        programmedContext($directory),
    );

    expect($retry['retried_checks'])->toBe(['M04.1', 'M04.12', 'M04.16'])
        ->and($retry['native_checks_run'])->toBe(['M04.1', 'M04.12', 'M04.16'])
        ->and($retry['run_id'])->toBe($first['run_id'])
        ->and(outcomeOf($retry, 'M04.1'))->toBe('PASS')
        ->and(outcomeOf($retry, 'M04.2'))->toBe('PASS')
        ->and($retry['summary'])->toMatchArray(['product_failures' => 0, 'permission_blocks' => 0, 'passes' => 16])
        ->and(file_get_contents(attemptPath($directory, $first, 'M04.1', 1)))->toBe($blockedAttempt)
        ->and(file_get_contents(attemptPath($directory, $first, 'M04.2', 1)))->toBe($passedAttempt)
        ->and(is_file(attemptPath($directory, $first, 'M04.2', 2)))->toBeFalse()
        ->and(json_decode(file_get_contents(attemptPath($directory, $first, 'M04.1', 2)), true)['outcome'])->toBe('PASS')
        ->and(file_get_contents($directory.'/runs/'.$first['run_id'].'/preflight-attempt-1.json'))->toBe($preflight);
});

it('rejects stale evidence when the candidate SHA changed', function () {
    $directory = verificationDirectory();
    bindPermissions(PermissionState::Denied, PermissionState::Granted);
    $first = app(VerifyMolly::class)->verify(VerificationMode::Default, $directory, verificationSha(), programmedContext($directory));
    $before = hash_file('sha256', attemptPath($directory, $first, 'M04.1', 1));

    $retry = app(VerifyMolly::class)->verify(VerificationMode::RetryNativeUi, $directory, str_repeat('cd', 20), programmedContext($directory));

    expect($retry['exit_code'])->toBe(4)
        ->and($retry['reason_code'])->toBe('CANDIDATE_SHA_MISMATCH')
        ->and($retry['reused_evidence'])->toBeFalse()
        ->and(hash_file('sha256', attemptPath($directory, $first, 'M04.1', 1)))->toBe($before)
        ->and(is_file(attemptPath($directory, $first, 'M04.1', 2)))->toBeFalse();
});

it('rejects stale evidence when the verifier environment changed', function () {
    $directory = verificationDirectory();
    bindPermissions(PermissionState::Granted, PermissionState::Granted);
    $first = app(VerifyMolly::class)->verify(VerificationMode::Permissionless, $directory, verificationSha(), programmedContext($directory));
    $path = $directory.'/runs/'.$first['run_id'].'/result.json';
    $saved = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $saved['environment']['os_family'] = 'Darwin';
    file_put_contents($path, json_encode($saved, JSON_THROW_ON_ERROR));
    $before = hash_file('sha256', $path);

    $retry = app(VerifyMolly::class)->verify(VerificationMode::RetryNativeUi, $directory, verificationSha());

    expect($retry['exit_code'])->toBe(4)
        ->and($retry['reason_code'])->toBe('ENVIRONMENT_CHANGED')
        ->and(hash_file('sha256', $path))->toBe($before);
});

it('reports a product failure in permissionless mode', function () {
    $directory = verificationDirectory();
    $context = new VerificationContext(verificationSha(), '', PackageRoot::path(), $directory, VerificationMode::Permissionless, null, [], [], [], [
        'settings_match' => false,
    ]);

    $document = app(VerifyMolly::class)->verify(VerificationMode::Permissionless, $directory, verificationSha(), $context);

    expect(outcomeOf($document, 'M04.9'))->toBe('PRODUCT_FAIL')
        ->and(outcomeOf($document, 'M04.2'))->toBe('BLOCKED_VERIFIER_PERMISSION')
        ->and($document['summary']['product_failures'])->toBe(1)
        ->and($document['exit_code'])->toBe(1)
        ->and($document['permission_granted_by_cli'])->toBeFalse();
});

it('reports a broken fixture as a harness failure', function () {
    $directory = verificationDirectory();
    $context = programmedContext($directory, ['harnessBroken' => ['M04.4']]);

    $document = app(VerifyMolly::class)->verify(VerificationMode::Permissionless, $directory, verificationSha(), $context);

    expect(outcomeOf($document, 'M04.4'))->toBe('HARNESS_FAIL')
        ->and(outcomeOf($document, 'M04.4'))->not->toBe('PRODUCT_FAIL')
        ->and($document['summary']['harness_failures'])->toBe(1)
        ->and($document['summary']['product_failures'])->toBe(0)
        ->and($document['exit_code'])->toBe(2);
});

it('does not let saved permission history override the current inspection', function () {
    $directory = verificationDirectory();
    file_put_contents($directory.'/permission-history.json', json_encode([
        'schema' => 'molly.verifier-permission-history/1',
        'entries' => [[
            'screen_recording' => 'granted',
            'accessibility' => 'granted',
            'disposition' => 'granted',
        ]],
    ], JSON_THROW_ON_ERROR));
    bindPermissions(PermissionState::Denied, PermissionState::Denied);

    $document = app(VerifyMolly::class)->inspectPermissions($directory, verificationSha());

    expect($document['screen_recording'])->toBe('denied')
        ->and($document['accessibility'])->toBe('denied')
        ->and($document['previous_disposition'])->toBe('granted')
        ->and($document['changed_since_previous'])->toBeTrue()
        ->and($document['permission_granted_by_cli'])->toBeFalse()
        ->and($document['exit_code'])->toBe(0);
});

it('blocks the native nav check when host telemetry exists and screen recording does not', function () {
    $directory = verificationDirectory();
    $telemetry = $directory.'/host.json';
    file_put_contents($telemetry, json_encode(['plugin_discovered' => true, 'plugin_id' => 'sifrious.molly'], JSON_THROW_ON_ERROR));
    bindPermissions(PermissionState::NotDetermined, PermissionState::Granted);
    $context = new VerificationContext(verificationSha(), '', PackageRoot::path(), $directory, VerificationMode::Default, $telemetry);

    $document = app(VerifyMolly::class)->verify(VerificationMode::Permissionless, $directory, verificationSha(), $context);

    expect(outcomeOf($document, 'M04.1'))->toBe('BLOCKED_VERIFIER_PERMISSION')
        ->and($document['summary']['product_failures'])->toBe(0)
        ->and($document['preflight']['checks_affected'])->toContain('M04.1')
        ->and($document['preflight']['checks_unaffected'])->toContain('M04.4');
});

it('does not hide a broken plugin seam behind a missing permission', function () {
    $directory = verificationDirectory();
    $root = $directory.'/empty-repo';
    mkdir($root, 0700, true);
    bindPermissions(PermissionState::Denied, PermissionState::Denied);
    $context = new VerificationContext(verificationSha(), '', $root, $directory, VerificationMode::Permissionless);

    $document = app(VerifyMolly::class)->verify(VerificationMode::Permissionless, $directory, verificationSha(), $context);

    expect(outcomeOf($document, 'M04.1'))->toBe('PRODUCT_FAIL')
        ->and(outcomeOf($document, 'M04.2'))->toBe('BLOCKED_VERIFIER_PERMISSION')
        ->and($document['exit_code'])->toBe(1);
});

it('reports this machine as unsupported and does not claim a grant', function () {
    $directory = verificationDirectory();

    $permissions = app(MacOsVerifierPermissionInspector::class)->inspect();
    $document = app(VerifyMolly::class)->verify(VerificationMode::Default, $directory, verificationSha());

    expect(PHP_OS_FAMILY)->not->toBe('Darwin')
        ->and($permissions->screenRecording)->toBe(PermissionState::Unsupported)
        ->and($permissions->accessibility)->toBe(PermissionState::Unsupported)
        ->and($document['permission_granted_by_cli'])->toBeFalse()
        ->and($document['preflight']['screen_recording'])->toBe('unsupported')
        ->and($document['preflight']['request_attempted'])->toBeFalse()
        ->and($document['preflight']['settings_opened'])->toBe([])
        ->and($document['summary']['product_failures'])->toBe(0)
        ->and(outcomeOf($document, 'M04.1'))->toBe('BLOCKED_PREREQUISITE')
        ->and(outcomeOf($document, 'M04.2'))->toBe('BLOCKED_VERIFIER_PERMISSION');
});

it('does not claim the CLI granted a permission the probe did not request', function () {
    $granted = permissionSnapshot(PermissionState::Granted, PermissionState::Granted);
    app()->instance(VerifierPermissionInspector::class, new SequencePermissionInspector([$granted]));

    $result = app(MacOsVerifierPermissionRequester::class)->requestMissing(
        permissionSnapshot(PermissionState::NotDetermined, PermissionState::NotDetermined),
    );

    expect($result->requestAttempted)->toBeFalse()
        ->and($result->permissionGrantedByCli)->toBeFalse()
        ->and($result->settingsOpened)->toBe([])
        ->and($result->after->screenRecording)->toBe(PermissionState::Granted);
});

it('records a grant only from the inspector after a request, and opens settings when macOS does not grant it', function () {
    $prompt = new StubPermissionPrompt;
    $granted = new SequencePermissionInspector([permissionSnapshot(PermissionState::Granted, PermissionState::Granted)]);
    $grantedResult = (new MacOsVerifierPermissionRequester($granted, $prompt))->requestMissing(
        permissionSnapshot(PermissionState::NotDetermined, PermissionState::Granted),
    );
    $deniedPrompt = new StubPermissionPrompt;
    $denied = new SequencePermissionInspector([permissionSnapshot(PermissionState::NotDetermined, PermissionState::Granted)]);
    $deniedResult = (new MacOsVerifierPermissionRequester($denied, $deniedPrompt))->requestMissing(
        permissionSnapshot(PermissionState::NotDetermined, PermissionState::Granted),
    );

    expect($grantedResult->permissionGrantedByCli)->toBeTrue()
        ->and($grantedResult->settingsOpened)->toBe([])
        ->and($deniedResult->permissionGrantedByCli)->toBeFalse()
        ->and($deniedResult->after->screenRecording)->toBe(PermissionState::Denied)
        ->and($deniedResult->settingsOpened)->toBe([PermissionKind::ScreenRecording->settingsUrl()])
        ->and($deniedPrompt->requested)->toBeTrue();
});

it('serves the same verification through the command and MCP', function () {
    $directory = verificationDirectory();
    $sha = verificationSha();

    [$exit, $json] = mollyJson('molly:verify', [
        '--check-permissions' => true,
        '--candidate' => $sha,
        '--evidence' => $directory,
    ]);
    $conflict = mollyJson('molly:verify', [
        '--permissionless' => true,
        '--retry-native-ui' => true,
        '--candidate' => $sha,
        '--evidence' => $directory,
    ]);
    MollyServer::tools()->assertRegistered([MollyVerify::class]);
    $inspected = MollyServer::tool(MollyVerify::class, [
        'operation' => 'inspect_permissions',
        'evidence' => $directory,
        'candidate' => $sha,
    ]);
    $ran = MollyServer::tool(MollyVerify::class, [
        'operation' => 'run_permissionless',
        'evidence' => $directory,
        'candidate' => $sha,
    ]);

    expect($exit)->toBe(0)
        ->and($json['screen_recording'])->toBe('unsupported')
        ->and($json['permission_granted_by_cli'])->toBeFalse()
        ->and($json['checks_affected'])->toContain('M04.2')
        ->and($conflict[0])->toBe(4)
        ->and($conflict[1]['reason_code'])->toBe('VERIFICATION_MODE_CONFLICT');
    $inspected->assertOk()->assertSee(['unsupported', 'permission_granted_by_cli']);
    $ran->assertOk()->assertSee(['BLOCKED_VERIFIER_PERMISSION', 'product_failures', 'permission_granted_by_cli']);

    $latest = MollyServer::tool(MollyVerify::class, ['operation' => 'latest', 'evidence' => $directory]);
    $latest->assertOk()->assertSee(['M04.2', $sha]);
    $evidence = MollyServer::tool(MollyVerify::class, [
        'operation' => 'check_evidence',
        'evidence' => $directory,
        'check_id' => 'M04.2',
    ]);
    $evidence->assertOk()->assertSee(['BLOCKED_VERIFIER_PERMISSION', 'M04.2']);
});

it('does not ship a TCC reset or a CLI grant', function () {
    $source = '';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src/Acceptance'));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source .= file_get_contents($file->getPathname());
        }
    }

    expect($source)->not->toContain('tccutil')
        ->and($source)->not->toContain('TCC.db')
        ->and($source)->not->toContain('csrutil');
});
