<?php

namespace Sifrious\Molly\Actions;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use Sifrious\Molly\Contracts\JsonDocument;
use Sifrious\Molly\Contracts\VerificationOutcome;
use Sifrious\Molly\Verification\FailureAction;
use Sifrious\Molly\Verification\VerificationState;
use Sifrious\Molly\Verification\VerifierPolicy;
use Sifrious\Molly\Workspace;

final class RecordVerificationReceipts
{
    /**
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    public function handle(string $workspace, string $runId, array $report): array
    {
        $finishedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $receipts = [];
        foreach ($report['verification_outcomes'] ?? [] as $name => $outcome) {
            if (! is_string($name) || ! is_array($outcome)) {
                continue;
            }

            $directory = $this->directory($workspace, $runId);
            $path = $directory.'/'.$name.'.json';
            if (is_file($path) && ! is_link($path)) {
                $receipts[] = [...VerificationOutcome::fromJson(trim((string) File::get($path)))->toArray(), 'path' => $path];

                continue;
            }

            $payload = $this->payload($name, $report);
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
            $this->write($path, $receipt);
            $receipts[] = [...$receipt->toArray(), 'path' => $path];
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
     * @param  array<string, mixed>  $report
     */
    private function diagnosticsRef(string $name, array $report): ?string
    {
        return match ($name) {
            'pest' => is_string($report['verification']['junit'] ?? null) ? $report['verification']['junit'] : null,
            'parallel_join' => 'branches',
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
        $directory = (new Workspace($workspace))->path.'/.molly/receipts/'.$runId;
        File::ensureDirectoryExists($directory, 0700);

        return $directory;
    }

    private function write(string $path, VerificationOutcome $receipt): void
    {
        $mask = umask(0077);
        try {
            if (file_put_contents($path, $receipt->toJson()."\n", LOCK_EX) === false) {
                throw new \RuntimeException('RECEIPT_UNWRITABLE: Molly could not record the verification receipt.');
            }
        } finally {
            umask($mask);
        }
    }
}
