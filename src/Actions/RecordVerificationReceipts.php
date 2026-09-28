<?php

namespace Sifrious\Molly\Actions;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Sifrious\Molly\Contracts\JsonDocument;
use Sifrious\Molly\Contracts\VerificationOutcome;
use Sifrious\Molly\Redaction\SecretRedactor;
use Sifrious\Molly\Verification\FailureAction;
use Sifrious\Molly\Verification\VerificationState;
use Sifrious\Molly\Verification\VerifierPolicy;
use Sifrious\Molly\Workspace;
use Sifrious\Molly\Workspace\Directory;

final class RecordVerificationReceipts
{
    public function __construct(private SecretRedactor $redactor) {}

    /**
     * Receipts are immutable and digest-covered, so the report is redacted before
     * any payload is digested or written.
     *
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    public function handle(string $workspace, string $runId, array $report): array
    {
        $finishedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $report = $this->redactor->value($report, $workspace);
        $receipts = [];
        foreach ($report['verification_outcomes'] ?? [] as $name => $outcome) {
            if (! is_string($name) || ! is_array($outcome)) {
                continue;
            }

            $directory = $this->directory($workspace, $runId);
            $path = $directory.'/'.$name.'.json';
            if (is_file($path) && ! is_link($path)) {
                $json = trim((string) File::get($path));
                $saved = JsonDocument::decode($json)['context'] ?? null;
                $receipts[] = [...VerificationOutcome::fromJson($json)->toArray(), ...(is_array($saved) ? ['context' => $saved] : []), 'path' => $path];

                continue;
            }

            $context = $this->context($report);
            $payload = [...$this->payload($name, $report), ...($context === [] ? [] : ['context' => $context])];
            $digest = hash('sha256', JsonDocument::encode($payload));
            $startedAt = $this->startedAt($name, $report, $finishedAt);
            $receipt = new VerificationOutcome(
                $name,
                VerificationState::from($outcome['state']),
                VerifierPolicy::from($outcome['policy']),
                FailureAction::from($outcome['failure_action']),
                $this->diagnosticsRef($name, $report),
                $digest,
                $startedAt,
                $finishedAt,
                $this->anotherAttemptPermitted($outcome),
            );
            $this->write($path, $receipt, $context);
            $receipts[] = [...$receipt->toArray(), ...($context === [] ? [] : ['context' => $context]), 'path' => $path];
        }

        return $receipts;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function payload(string $name, array $report): array
    {
        return match ($name) {
            'pest' => [
                'status' => $report['verification']['status'] ?? null,
                'tests' => $report['verification']['tests'] ?? null,
                'assertions' => $report['verification']['assertions'] ?? null,
                'failures' => $report['verification']['failures'] ?? null,
                'errors' => $report['verification']['errors'] ?? null,
                'skipped' => $report['verification']['skipped'] ?? null,
                'reason' => $report['verification']['reason'] ?? null,
                'identified_required_test' => $report['verification']['identified_required_test'] ?? null,
                'junit_digest' => $this->fileDigest($report['verification']['junit'] ?? null),
            ],
            'tarpit' => [
                'checks' => $report['review']['checks'] ?? null,
                'findings' => $report['review']['findings'] ?? null,
                'reason' => $report['review']['reason'] ?? null,
            ],
            'false_green' => [
                'status' => $report['false_green']['status'] ?? null,
                'conclusion' => $report['false_green']['conclusion'] ?? null,
                'test_path' => $report['false_green']['test_path'] ?? null,
                'probes' => $report['false_green']['probes'] ?? null,
                'reason' => $report['false_green']['reason'] ?? null,
                'budgets' => $report['false_green']['budgets'] ?? null,
            ],
            'parallel_join' => [
                'branches' => array_map(fn (array $branch): array => [
                    'kind' => $branch['kind'] ?? null,
                    'status' => $branch['status'] ?? null,
                    'result_ref' => $branch['result_ref'] ?? null,
                    'finished_at' => $branch['finished_at'] ?? null,
                    'result_digest' => $this->fileDigest($branch['result_ref'] ?? null),
                ], $report['branches'] ?? []),
            ],
            default => $report['verification_outcomes'][$name] ?? [],
        };
    }

    /**
     * Run facts every receipt carries beside the verifier outcome: the RED
     * baseline of the locked test and the identity of the model that wrote
     * the changes. The evidence digest covers them.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function context(array $report): array
    {
        $context = [];
        if (is_array($report['red_baseline'] ?? null)) {
            $context['red_baseline'] = array_intersect_key($report['red_baseline'], array_flip([
                'classification', 'reason', 'tests', 'failures', 'errors', 'junit_digest', 'test_digest', 'recorded_at',
            ]));
        }
        if (is_array($report['model_identity'] ?? null)) {
            $context['model_identity'] = $report['model_identity'];
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function diagnosticsRef(string $name, array $report): ?string
    {
        return match ($name) {
            'pest' => is_string($report['verification']['junit'] ?? null) ? $report['verification']['junit'] : null,
            'parallel_join' => 'branches',
            'false_green' => 'false_green.probes',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function startedAt(string $name, array $report, DateTimeImmutable $fallback): DateTimeImmutable
    {
        foreach ($report['branches'] ?? [] as $branch) {
            if (($branch['kind'] ?? null) === ($name === 'pest' ? 'verification' : ($name === 'tarpit' ? 'review' : null))
                && is_string($branch['started_at'] ?? null)) {
                try {
                    return new DateTimeImmutable($branch['started_at']);
                } catch (\Exception) {
                    return $fallback;
                }
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function anotherAttemptPermitted(array $outcome): bool
    {
        return ($outcome['state'] ?? null) !== VerificationState::Pass->value
            && ($outcome['failure_action'] ?? null) === FailureAction::Retry->value;
    }

    private function fileDigest(mixed $path): ?string
    {
        if (! is_string($path) || is_link($path) || ! is_file($path)) {
            return null;
        }

        return hash_file('sha256', $path) ?: null;
    }

    private function directory(string $workspace, string $runId): string
    {
        $directory = Directory::molly((new Workspace($workspace))->path, 'receipts/'.$runId);
        Directory::ensure($directory, 0700);

        return $directory;
    }

    /** @param  array<string, mixed>  $context */
    private function write(string $path, VerificationOutcome $receipt, array $context): void
    {
        $json = $context === [] ? $receipt->toJson() : JsonDocument::encode([...$receipt->toArray(), 'context' => $context]);
        $mask = umask(0077);
        try {
            if (file_put_contents($path, $json."\n", LOCK_EX) === false) {
                throw new \RuntimeException('RECEIPT_UNWRITABLE: Molly could not record the verification receipt.');
            }
        } finally {
            umask($mask);
        }
    }
}
