<?php

use Sifrious\Molly\Acceptance\CheckOutcome;
use Sifrious\Molly\Acceptance\M04CheckCatalog;
use Sifrious\Molly\Acceptance\PermissionHistoryDisposition;
use Sifrious\Molly\Acceptance\PermissionKind;
use Sifrious\Molly\Acceptance\PermissionRequestResult;
use Sifrious\Molly\Acceptance\PermissionState;
use Sifrious\Molly\Acceptance\VerificationCheckResult;
use Sifrious\Molly\Acceptance\VerificationMode;
use Sifrious\Molly\Acceptance\VerificationPlan;
use Sifrious\Molly\Acceptance\VerificationSummary;
use Sifrious\Molly\Acceptance\VerifierPermissionSnapshot;
use Sifrious\Molly\Acceptance\VerifierProcessIdentity;

function classificationSnapshot(PermissionState $screen, PermissionState $accessibility): VerifierPermissionSnapshot
{
    return new VerifierPermissionSnapshot(
        $screen,
        $accessibility,
        VerifierProcessIdentity::capture(),
        'Darwin',
        '15.6',
        'classification-test',
        new DateTimeImmutable('2026-10-03T14:00:00Z'),
    );
}

function classificationResult(string $id, CheckOutcome $outcome): VerificationCheckResult
{
    $now = new DateTimeImmutable('2026-10-03T14:00:00Z');

    return new VerificationCheckResult(
        $id,
        str_repeat('a', 40),
        '11111111-1111-1111-1111-111111111111',
        'bloom',
        $outcome,
        $outcome->value,
        $id.' '.$outcome->value,
        [],
        ['screen_recording' => 'granted', 'accessibility' => 'granted'],
        $now,
        $now,
        $outcome->exitCode(),
        [],
    );
}

it('keeps granted, denied, not determined, unsupported, and unknown separate for both permissions', function () {
    $states = [PermissionState::Granted, PermissionState::Denied, PermissionState::NotDetermined, PermissionState::Unsupported, PermissionState::Unknown];

    foreach ($states as $screen) {
        foreach ($states as $accessibility) {
            $snapshot = classificationSnapshot($screen, $accessibility);

            expect($snapshot->state(PermissionKind::ScreenRecording))->toBe($screen)
                ->and($snapshot->state(PermissionKind::Accessibility))->toBe($accessibility)
                ->and($snapshot->toArray()['screen_recording'])->toBe($screen->value)
                ->and($snapshot->toArray()['accessibility'])->toBe($accessibility->value);
        }
    }
});

it('sets permission_granted_by_cli only when a request was attempted and macOS reports the new grant', function () {
    $missing = classificationSnapshot(PermissionState::NotDetermined, PermissionState::Granted);
    $granted = classificationSnapshot(PermissionState::Granted, PermissionState::Granted);
    $stillMissing = classificationSnapshot(PermissionState::NotDetermined, PermissionState::Granted);

    $grantedByOs = PermissionRequestResult::fromInspection($missing, $granted, true, []);
    $requestDidNotGrant = PermissionRequestResult::fromInspection($missing, $stillMissing, true, [PermissionKind::ScreenRecording->settingsUrl()]);
    $alreadyGranted = PermissionRequestResult::fromInspection($granted, $granted, true, []);
    $noRequest = PermissionRequestResult::fromInspection($missing, $granted, false, []);

    expect($grantedByOs->permissionGrantedByCli)->toBeTrue()
        ->and($grantedByOs->after->screenRecording)->toBe(PermissionState::Granted)
        ->and($requestDidNotGrant->permissionGrantedByCli)->toBeFalse()
        ->and($requestDidNotGrant->after->screenRecording)->toBe(PermissionState::Denied)
        ->and($requestDidNotGrant->userActionRequired)->toBeTrue()
        ->and($alreadyGranted->permissionGrantedByCli)->toBeFalse()
        ->and($noRequest->permissionGrantedByCli)->toBeFalse()
        ->and($noRequest->after->screenRecording)->toBe(PermissionState::Granted);
});

it('does not turn two permission blocks and two passes into an M04 failure', function () {
    $results = [
        classificationResult('M04.1', CheckOutcome::Pass),
        classificationResult('M04.2', CheckOutcome::BlockedVerifierPermission),
        classificationResult('M04.3', CheckOutcome::BlockedVerifierPermission),
        classificationResult('M04.4', CheckOutcome::Pass),
    ];

    $stage = VerificationSummary::stage('M04', $results);
    $summary = VerificationSummary::fromResults($results);

    expect($stage)->toMatchArray([
        'stage' => 'M04',
        'product_failures' => 0,
        'harness_failures' => 0,
        'permission_blocks' => 2,
        'passes' => 2,
        'release_complete' => false,
    ])
        ->and($summary->exitCode(VerificationMode::Default))->toBe(3)
        ->and($summary->exitCode(VerificationMode::Permissionless))->toBe(0)
        ->and(CheckOutcome::BlockedVerifierPermission->acceptanceRecordOutcome())->toBe('BLOCKED')
        ->and(CheckOutcome::ProductFail->acceptanceRecordOutcome())->toBe('FAIL');
});

it('plans every M04 check in permissionless mode and retries only permission blocks', function () {
    $catalog = new M04CheckCatalog;
    $plan = VerificationPlan::full($catalog, VerificationMode::Permissionless);
    $retry = VerificationPlan::retry($catalog->checks(), [
        ['check_id' => 'M04.1', 'outcome' => 'PASS'],
        ['check_id' => 'M04.2', 'outcome' => 'BLOCKED_VERIFIER_PERMISSION'],
        ['check_id' => 'M04.4', 'outcome' => 'PRODUCT_FAIL'],
        ['check_id' => 'M04.12', 'outcome' => 'BLOCKED_VERIFIER_PERMISSION'],
        ['check_id' => 'M04.14', 'outcome' => 'BLOCKED_PREREQUISITE'],
        ['check_id' => 'M04.16', 'outcome' => 'EVIDENCE_INCOMPLETE'],
    ]);

    expect($plan->checks)->toHaveCount(16)
        ->and(array_map(fn ($check) => $check->id, $retry->checks))->toBe(['M04.2', 'M04.12'])
        ->and(PermissionHistoryDisposition::NeverChecked->value)->toBe('never_checked');
});

it('keeps screen recording checks and accessibility checks distinct', function () {
    $screen = [];
    $accessibility = [];
    foreach ((new M04CheckCatalog)->checks() as $check) {
        $kinds = array_map(fn (PermissionKind $kind) => $kind->value, $check->requiredPermissions);
        if ($kinds === ['screen_recording']) {
            $screen[] = $check->id;
        }
        if ($kinds === ['accessibility']) {
            $accessibility[] = $check->id;
        }
    }

    expect($screen)->toBe(['M04.1', 'M04.12', 'M04.16'])
        ->and($accessibility)->toBe(['M04.2', 'M04.3', 'M04.10']);
});
