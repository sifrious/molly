<?php

namespace Sifrious\Molly\Acceptance;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class VerificationRunner
{
    /** @var list<string> */
    private array $nativeChecksRun = [];

    public function __construct(
        private RepoBloomCheckProbe $probe,
        private CompiledHostBloomObserver $observer,
        private VerificationResultClassifier $classifier,
        private EvidenceRecorder $evidence,
    ) {}

    /**
     * @return array{results: list<VerificationCheckResult>, native_checks_run: list<string>}
     */
    public function run(VerificationPlan $plan, VerifierPermissionSnapshot $snapshot, VerificationContext $context): array
    {
        $this->nativeChecksRun = [];
        $results = [];
        foreach ($plan->checks as $check) {
            $results[] = $this->one($check, $snapshot, $context);
        }

        return ['results' => $results, 'native_checks_run' => $this->nativeChecksRun];
    }

    private function one(VerificationCheckDefinition $check, VerifierPermissionSnapshot $snapshot, VerificationContext $context): VerificationCheckResult
    {
        $started = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $attempt = $this->evidence->nextAttempt($context->evidenceRoot, $context->runId, $check->id);
        try {
            $probe = $this->probe->probe($check, $context);
        } catch (Throwable $exception) {
            return $this->store($this->classifier->harness($check, 'PROBE_BROKEN', $exception->getMessage(), $snapshot, $context, $started, $attempt), $context);
        }

        [$result, $needsNative] = $this->classifier->classify($check, $probe, $snapshot, $context, $started, $attempt);
        if (! $needsNative) {
            return $this->store($result, $context);
        }

        try {
            $native = $this->observer->observe($check, $context);
        } catch (Throwable $exception) {
            return $this->store($this->classifier->harness($check, 'NATIVE_OBSERVER_FAILED', $exception->getMessage(), $snapshot, $context, $started, $attempt), $context);
        }
        $this->nativeChecksRun[] = $check->id;

        return $this->store($this->classifier->finishNative($check, $native, $snapshot, $context, $started, $attempt), $context);
    }

    private function store(VerificationCheckResult $result, VerificationContext $context): VerificationCheckResult
    {
        $path = $this->evidence->writeAttempt($context->evidenceRoot, $result);

        return new VerificationCheckResult(
            $result->checkId,
            $result->candidateSha,
            $result->runId,
            $result->interface,
            $result->outcome,
            $result->reasonCode,
            $result->message,
            $result->requiredPermissions,
            $result->observedPermissions,
            $result->startedAt,
            $result->finishedAt,
            $result->exitCode,
            [$path],
            $result->stage,
            $result->attempt,
            $result->assertions,
        );
    }
}
