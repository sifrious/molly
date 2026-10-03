<?php

namespace Sifrious\Molly\Acceptance;

use DateTimeImmutable;
use DateTimeZone;
use Sifrious\Molly\Contracts\JsonDocument;
use Throwable;

final class PermissionPreflight
{
    public function __construct(
        private VerifierPermissionInspector $inspector,
        private VerifierPermissionRequester $requester,
        private EvidenceRecorder $evidence,
        private M04CheckCatalog $catalog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(string $evidenceRoot, string $runId, string $candidateSha, bool $requestMissing): array
    {
        $before = $this->inspector->inspect();
        $request = $requestMissing && $this->shouldRequest($before)
            ? $this->requester->requestMissing($before)
            : PermissionRequestResult::fromInspection($before, $before, false, []);
        $after = $request->after;
        $affected = [];
        $unaffected = [];
        foreach ($this->catalog->checks() as $check) {
            if ($after->missing($check->requiredPermissions) !== []) {
                $affected[] = $check->id;
            } else {
                $unaffected[] = $check->id;
            }
        }
        $disposition = $this->disposition($after, $request->requestAttempted);
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $entry = [
            'at' => JsonDocument::formatTime($at),
            'run_id' => $runId,
            'candidate_sha' => $candidateSha,
            'screen_recording' => $after->screenRecording->value,
            'accessibility' => $after->accessibility->value,
            'disposition' => $disposition->value,
            'request_attempted' => $request->requestAttempted,
        ];
        $history = $this->evidence->appendPermissionHistory($evidenceRoot, $entry);

        return [
            'schema' => 'molly.verifier-permission-preflight/1',
            'run_id' => $runId,
            'candidate_sha' => $candidateSha,
            'machine' => $after->machine,
            'platform' => $after->platform,
            'macos_version' => $after->macosVersion,
            'process' => $after->process->toArray(),
            'bundle_id' => $after->process->bundleId,
            'screen_recording' => $after->screenRecording->value,
            'accessibility' => $after->accessibility->value,
            'preflight_at' => JsonDocument::formatTime($at),
            'request_attempted' => $request->requestAttempted,
            'settings_opened' => $request->settingsOpened,
            'user_action_required' => $request->userActionRequired,
            'permission_granted_by_cli' => $request->permissionGrantedByCli,
            'disposition' => $disposition->value,
            'previous_disposition' => $history['previous'],
            'changed_since_previous' => $history['changed'],
            'checks_affected' => $affected,
            'checks_unaffected' => $unaffected,
            'message' => $request->message,
            'snapshot' => $after,
        ];
    }

    /** @return array<string, mixed> */
    public function failed(string $evidenceRoot, string $runId, string $candidateSha, Throwable $exception): array
    {
        return [
            'schema' => 'molly.verifier-permission-preflight/1',
            'run_id' => $runId,
            'candidate_sha' => $candidateSha,
            'machine' => php_uname('n'),
            'platform' => PHP_OS_FAMILY,
            'macos_version' => null,
            'process' => VerifierProcessIdentity::capture()->toArray(),
            'bundle_id' => null,
            'screen_recording' => null,
            'accessibility' => null,
            'preflight_at' => JsonDocument::formatTime(new DateTimeImmutable('now', new DateTimeZone('UTC'))),
            'request_attempted' => false,
            'settings_opened' => [],
            'user_action_required' => false,
            'permission_granted_by_cli' => false,
            'disposition' => PermissionHistoryDisposition::CheckedMissing->value,
            'previous_disposition' => PermissionHistoryDisposition::NeverChecked->value,
            'changed_since_previous' => false,
            'checks_affected' => [],
            'checks_unaffected' => [],
            'message' => $exception->getMessage(),
            'snapshot' => null,
            'harness_failure' => true,
        ];
    }

    private function shouldRequest(VerifierPermissionSnapshot $snapshot): bool
    {
        if (! $snapshot->supportsRequest()) {
            return false;
        }
        foreach (PermissionKind::cases() as $kind) {
            $state = $snapshot->state($kind);
            if ($state === PermissionState::Denied || $state === PermissionState::NotDetermined) {
                return true;
            }
        }

        return false;
    }

    private function disposition(VerifierPermissionSnapshot $snapshot, bool $requestAttempted): PermissionHistoryDisposition
    {
        $states = [$snapshot->screenRecording, $snapshot->accessibility];
        if (! in_array(PermissionState::Denied, $states, true)
            && ! in_array(PermissionState::NotDetermined, $states, true)
            && in_array(PermissionState::Granted, $states, true)) {
            return PermissionHistoryDisposition::Granted;
        }
        if (in_array(PermissionState::Denied, $states, true)) {
            return PermissionHistoryDisposition::Denied;
        }
        if ($requestAttempted) {
            return PermissionHistoryDisposition::Requested;
        }

        return PermissionHistoryDisposition::CheckedMissing;
    }
}
