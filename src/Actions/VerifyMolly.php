<?php

namespace Sifrious\Molly\Actions;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Sifrious\Molly\Acceptance\CheckOutcome;
use Sifrious\Molly\Acceptance\EvidenceRecorder;
use Sifrious\Molly\Acceptance\M04CheckCatalog;
use Sifrious\Molly\Acceptance\PackageRoot;
use Sifrious\Molly\Acceptance\PermissionPreflight;
use Sifrious\Molly\Acceptance\VerificationCheckResult;
use Sifrious\Molly\Acceptance\VerificationContext;
use Sifrious\Molly\Acceptance\VerificationMode;
use Sifrious\Molly\Acceptance\VerificationPlan;
use Sifrious\Molly\Acceptance\VerificationRunner;
use Sifrious\Molly\Acceptance\VerificationSummary;
use Sifrious\Molly\Acceptance\VerifierPermissionSnapshot;
use Sifrious\Molly\Acceptance\VerifierProcessIdentity;
use Sifrious\Molly\Contracts\JsonDocument;
use Throwable;

class VerifyMolly
{
    public function __construct(
        private PermissionPreflight $preflight,
        private VerificationRunner $runner,
        private EvidenceRecorder $evidence,
        private M04CheckCatalog $catalog,
    ) {}

    /** @return array<string, mixed> */
    public function inspectPermissions(string $evidenceRoot, ?string $candidateSha = null): array
    {
        $invalid = $this->guard($evidenceRoot);
        if ($invalid !== null) {
            return $invalid;
        }
        $sha = $this->candidate($candidateSha);
        if (! is_string($sha)) {
            return $this->invalid($sha['reason_code'], $sha['message']);
        }

        try {
            $report = $this->preflight->run($evidenceRoot, (string) Str::uuid(), $sha, false);
        } catch (Throwable $exception) {
            return $this->harnessDocument($exception->getMessage(), $sha);
        }
        unset($report['snapshot']);
        $report['schema'] = 'molly.verifier-permissions/1';
        $report['exit_code'] = 0;
        $report['acceptance_program_complete'] = false;

        return $report;
    }

    /** @return array<string, mixed> */
    public function verify(VerificationMode $mode, string $evidenceRoot, ?string $candidateSha = null, ?VerificationContext $context = null, ?string $projectRoot = null): array
    {
        if ($mode === VerificationMode::CheckPermissions) {
            return $this->inspectPermissions($evidenceRoot, $candidateSha);
        }
        $invalid = $this->guard($evidenceRoot);
        if ($invalid !== null) {
            return $invalid;
        }
        $resolved = $this->candidate($candidateSha);
        if (! is_string($resolved)) {
            return $this->invalid((string) $resolved['reason_code'], (string) $resolved['message']);
        }
        $context = $this->context($mode, $context, $projectRoot);
        if ($mode === VerificationMode::RetryNativeUi) {
            return $this->retry($evidenceRoot, $resolved, $context);
        }

        return $this->fresh($mode, $evidenceRoot, $resolved, $context);
    }

    /** @return array<string, mixed> */
    public function latest(string $evidenceRoot): array
    {
        $run = $this->evidence->latest($evidenceRoot);
        if ($run === null) {
            return $this->invalid('VERIFICATION_NOT_FOUND', 'Molly has no saved verification evidence in that directory.');
        }

        return $run;
    }

    /** @return array<string, mixed> */
    public function checkEvidence(string $evidenceRoot, string $checkId, ?int $attempt = null): array
    {
        $run = $this->evidence->latest($evidenceRoot);
        if ($run === null || ! is_string($run['run_id'] ?? null)) {
            return $this->invalid('VERIFICATION_NOT_FOUND', 'Molly has no saved verification evidence in that directory.');
        }
        $record = $this->evidence->attempt($evidenceRoot, $run['run_id'], $checkId, $attempt);
        if ($record === null) {
            return $this->invalid('CHECK_EVIDENCE_NOT_FOUND', 'Molly has no saved evidence for '.$checkId.'.');
        }

        return $record;
    }

    /** @return array<string, mixed> */
    private function fresh(VerificationMode $mode, string $evidenceRoot, string $sha, VerificationContext $context): array
    {
        $runId = (string) Str::uuid();
        $context = $context->forRun($runId, $sha, $evidenceRoot, $mode);

        return $this->execute($mode, $evidenceRoot, $sha, $runId, VerificationPlan::full($this->catalog, $mode), $context, null);
    }

    /** @return array<string, mixed> */
    private function retry(string $evidenceRoot, string $sha, VerificationContext $context): array
    {
        $previous = $this->evidence->latest($evidenceRoot);
        if ($previous === null || ! is_string($previous['run_id'] ?? null) || ! is_array($previous['checks'] ?? null)) {
            return $this->invalid('RETRY_BASELINE_MISSING', 'Molly has no verification run to retry. Run php artisan molly:verify first.');
        }
        if (($previous['candidate_sha'] ?? null) !== $sha) {
            return $this->invalid(
                'CANDIDATE_SHA_MISMATCH',
                'The candidate SHA does not match the saved verification evidence. Molly did not reuse that evidence. Run php artisan molly:verify without --retry-native-ui for '.$sha.'.',
            );
        }
        $environment = $this->environment();
        if (! $this->sameEnvironment($previous['environment'] ?? null, $environment)) {
            return $this->invalid(
                'ENVIRONMENT_CHANGED',
                'The verifier environment changed since the saved run. Molly did not reuse that evidence. Run php artisan molly:verify without --retry-native-ui.',
            );
        }
        $plan = VerificationPlan::retry($this->catalog->checks(), $previous['checks']);
        if ($plan->checks === []) {
            $previous['retried_checks'] = [];
            $previous['message'] = 'No check was blocked only by a verifier permission, so Molly did not rerun any check.';

            return $previous;
        }
        $context = $context->forRun($previous['run_id'], $sha, $evidenceRoot, VerificationMode::RetryNativeUi);
        $document = $this->execute(
            VerificationMode::RetryNativeUi,
            $evidenceRoot,
            $sha,
            $previous['run_id'],
            $plan,
            $context,
            $previous,
        );
        $document['initial_mode'] = $previous['initial_mode'] ?? $previous['mode'] ?? VerificationMode::Default->value;

        return $document;
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @return array<string, mixed>
     */
    private function execute(
        VerificationMode $mode,
        string $evidenceRoot,
        string $sha,
        string $runId,
        VerificationPlan $plan,
        VerificationContext $context,
        ?array $previous,
    ): array {
        $started = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        try {
            $preflight = $this->preflight->run($evidenceRoot, $runId, $sha, $mode->requestsPermissions());
        } catch (Throwable $exception) {
            if ($previous !== null) {
                return $this->preservePrevious($evidenceRoot, $previous, $exception->getMessage());
            }
            $preflight = $this->preflight->failed($evidenceRoot, $runId, $sha, $exception);
        }
        if ($previous !== null && ($preflight['inspection_status'] ?? null) !== 'observed') {
            $this->evidence->writePreflight($evidenceRoot, $runId, $this->publicPreflight($preflight), $this->evidence->preflightAttempt($evidenceRoot, $runId));

            return $this->preservePrevious($evidenceRoot, $previous, (string) ($preflight['inspection_error'] ?? $preflight['message'] ?? 'Permission inspection failed.'));
        }

        $snapshot = $preflight['snapshot'] ?? null;
        if (! $snapshot instanceof VerifierPermissionSnapshot) {
            if ($previous !== null) {
                return $this->preservePrevious($evidenceRoot, $previous, 'Permission inspection returned no snapshot.');
            }
            $snapshot = VerifierPermissionSnapshot::unobserved(VerifierProcessIdentity::capture());
            $preflight['snapshot'] = $snapshot;
            $preflight['inspection_status'] = 'not_observed';
            $preflight['screen_recording'] = $snapshot->screenRecording->value;
            $preflight['accessibility'] = $snapshot->accessibility->value;
        }
        $executed = $this->runner->run($plan, $snapshot, $context);
        $results = $this->merge($previous, $executed['results']);
        $selected = array_map(fn ($check): string => $check->id, $plan->checks);
        $document = $this->document($mode, $sha, $runId, $started, $results, $preflight, $executed['native_checks_run'], $previous, null, $selected);
        $this->evidence->writePreflight($evidenceRoot, $runId, $this->publicPreflight($preflight), $this->evidence->preflightAttempt($evidenceRoot, $runId));
        $this->evidence->writeRun($evidenceRoot, $runId, $document);

        return $document;
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  list<VerificationCheckResult>  $fresh
     * @return list<VerificationCheckResult>
     */
    private function merge(?array $previous, array $fresh): array
    {
        if ($previous === null) {
            return $fresh;
        }
        $byId = [];
        foreach ($previous['checks'] as $check) {
            if (is_array($check) && is_string($check['check_id'] ?? null)) {
                $byId[$check['check_id']] = VerificationCheckResult::fromArray($check);
            }
        }
        foreach ($fresh as $result) {
            $byId[$result->checkId] = $result;
        }
        $ordered = [];
        foreach ($this->catalog->checks() as $definition) {
            if (isset($byId[$definition->id])) {
                $ordered[] = $byId[$definition->id];
            }
        }

        return $ordered;
    }

    /**
     * @param  list<VerificationCheckResult>  $results
     * @param  array<string, mixed>  $preflight
     * @param  list<string>  $nativeChecksRun
     * @param  array<string, mixed>|null  $previous
     * @param  list<string>  $selectedChecks
     * @return array<string, mixed>
     */
    private function document(
        VerificationMode $mode,
        string $sha,
        string $runId,
        DateTimeImmutable $started,
        array $results,
        array $preflight,
        array $nativeChecksRun,
        ?array $previous,
        ?string $message,
        array $selectedChecks,
    ): array {
        $summary = VerificationSummary::fromResults($results);
        $finished = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $exit = $summary->exitCode($mode);
        $blocked = array_values(array_map(
            fn (VerificationCheckResult $result): string => $result->checkId,
            array_filter($results, fn (VerificationCheckResult $result): bool => $result->outcome === CheckOutcome::BlockedVerifierPermission),
        ));

        return [
            'schema' => 'molly.acceptance-verification/1',
            'status' => 'completed',
            'mode' => $mode->value,
            'initial_mode' => $previous['initial_mode'] ?? $previous['mode'] ?? $mode->value,
            'scope' => 'M04',
            'run_id' => $runId,
            'candidate_sha' => $sha,
            'started_at' => JsonDocument::formatTime($started),
            'finished_at' => JsonDocument::formatTime($finished),
            'exit_code' => $exit,
            'release_complete' => $summary->releaseComplete,
            'acceptance_program_complete' => false,
            'permission_granted_by_cli' => $preflight['permission_granted_by_cli'] ?? false,
            'summary' => $summary->toArray(),
            'stages' => [VerificationSummary::stage('M04', $results)],
            'environment' => $previous['environment'] ?? $this->environment(),
            'preflight' => $this->publicPreflight($preflight),
            'checks' => array_map(fn (VerificationCheckResult $result): array => $result->toArray(), $results),
            'native_observation' => $mode === VerificationMode::Permissionless ? 'disabled_by_mode' : 'available',
            'native_checks_run' => $nativeChecksRun,
            'retried_checks' => $mode === VerificationMode::RetryNativeUi ? $selectedChecks : [],
            'permission_blocked_checks' => $blocked,
            'message' => $message ?? $this->summaryMessage($summary),
        ];
    }

    /** @param  array<string, mixed>  $preflight */
    private function publicPreflight(array $preflight): array
    {
        unset($preflight['snapshot']);

        return $preflight;
    }

    private function summaryMessage(VerificationSummary $summary): string
    {
        return 'M04 finished with '.$summary->productFailures.' product failures, '.$summary->harnessFailures
            .' harness failures, '.$summary->permissionBlocks.' permission blocks, and '.$summary->passes
            .' passes. '.($summary->releaseComplete ? 'Every check in this M04 run passed.' : 'Release acceptance is not complete.');
    }

    /** @return array<string, mixed>|null */
    private function guard(string $evidenceRoot): ?array
    {
        if ($evidenceRoot === '' || (is_file($evidenceRoot))) {
            return $this->invalid('EVIDENCE_PATH_INVALID', 'Pass a directory for verification evidence.');
        }

        return null;
    }

    /** @return string|array{reason_code: string, message: string} */
    private function candidate(?string $given): string|array
    {
        if (is_string($given) && $given !== '') {
            if (preg_match('/\A[0-9a-f]{40}\z/', $given) !== 1) {
                return ['reason_code' => 'CANDIDATE_SHA_INVALID', 'message' => 'The candidate SHA must be 40 lowercase hex characters.'];
            }

            return $given;
        }
        $result = Process::path(PackageRoot::path())->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
        $sha = trim($result->output());
        if (! $result->successful() || preg_match('/\A[0-9a-f]{40}\z/', $sha) !== 1) {
            return ['reason_code' => 'CANDIDATE_SHA_UNRESOLVED', 'message' => 'Molly could not read HEAD. Pass --candidate with the 40-character SHA.'];
        }

        return $sha;
    }

    /** @return array<string, mixed> */
    private function environment(): array
    {
        $process = VerifierProcessIdentity::capture();

        return [
            'os_family' => PHP_OS_FAMILY,
            'os_release' => php_uname('r'),
            'php_binary' => PHP_BINARY,
            'parent_name' => $process->parentName,
            'bundle_id' => $process->bundleId,
            'machine' => php_uname('n'),
        ];
    }

    /** @param  array<string, mixed>|null  $saved */
    private function sameEnvironment(?array $saved, array $current): bool
    {
        if ($saved === null) {
            return false;
        }
        foreach (['os_family', 'os_release', 'php_binary', 'parent_name', 'bundle_id', 'machine'] as $key) {
            if (($saved[$key] ?? null) !== ($current[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function context(VerificationMode $mode, ?VerificationContext $given, ?string $projectRoot): VerificationContext
    {
        $context = $given ?? new VerificationContext('', '', PackageRoot::path(), '', $mode);
        if (is_string($projectRoot) && $projectRoot !== '' && $context->projectRoot === '') {
            $context = $context->withProject($projectRoot);
        }

        return $context;
    }

    /** @param  array<string, mixed>  $previous */
    private function preservePrevious(string $evidenceRoot, array $previous, string $error): array
    {
        $checks = is_array($previous['checks'] ?? null) ? $previous['checks'] : [];
        $document = $previous;
        $document['status'] = 'completed';
        $document['release_complete'] = false;
        $document['acceptance_program_complete'] = false;
        $document['permission_granted_by_cli'] = false;
        $document['retried_checks'] = [];
        $document['native_checks_run'] = [];
        $document['checks'] = $checks;
        $document['preflight_diagnostic'] = [
            'outcome' => CheckOutcome::HarnessFail->value,
            'reason_code' => 'PERMISSION_PROBE_FAILED',
            'inspection_status' => 'not_observed',
            'inspection_error' => $error,
            'message' => 'Permission inspection failed. Molly kept the previous M04 results and did not rewrite check attempts.',
        ];
        $document['message'] = $document['preflight_diagnostic']['message'];
        $productFailures = (int) ($previous['summary']['product_failures'] ?? 0);
        $document['exit_code'] = $productFailures > 0 ? 1 : 2;
        if (is_string($previous['run_id'] ?? null)) {
            $this->evidence->writeRun($evidenceRoot, $previous['run_id'], $document);
        }

        return $document;
    }

    /** @return array<string, mixed> */
    private function invalid(string $reason, string $message): array
    {
        return [
            'schema' => 'molly.acceptance-verification/1',
            'status' => 'invalid',
            'exit_code' => 4,
            'reason_code' => $reason,
            'message' => $message,
            'release_complete' => false,
            'acceptance_program_complete' => false,
            'reused_evidence' => false,
            'permission_granted_by_cli' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function harnessDocument(string $message, string $sha): array
    {
        return [
            'schema' => 'molly.acceptance-verification/1',
            'status' => 'completed',
            'exit_code' => 2,
            'reason_code' => 'PERMISSION_PROBE_FAILED',
            'candidate_sha' => $sha,
            'message' => $message,
            'release_complete' => false,
            'acceptance_program_complete' => false,
            'permission_granted_by_cli' => false,
            'summary' => [
                'product_failures' => 0,
                'harness_failures' => 1,
                'permission_blocks' => 0,
                'prerequisite_blocks' => 0,
                'evidence_incomplete' => 0,
                'passes' => 0,
                'approved_na' => 0,
                'not_run' => 0,
                'release_complete' => false,
            ],
        ];
    }
}
