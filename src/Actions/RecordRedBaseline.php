<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;
use Throwable;

/**
 * Run the locked Pest test before implementation and record why it fails.
 * Only a failure for missing behavior counts as a usable RED baseline.
 */
class RecordRedBaseline
{
    public function __construct(private VerifyChanges $verify) {}

    /** @return array<string, mixed> */
    public function handle(Task $task): array
    {
        $recordedAt = now()->toIso8601String();
        try {
            $workspace = new Workspace($task->workspace);
            $evidence = storage_path('molly/red-baselines/'.$task->id.'/'.now()->format('Ymd\THis').'-'.bin2hex(random_bytes(4)));
            $verification = $workspace->exclusively(fn (): array => $this->verify->handle($workspace->path, $task->test_path, $evidence));
            $baseline = [
                ...$this->classify($verification),
                'tests' => $verification['tests'] ?? 0,
                'assertions' => $verification['assertions'] ?? 0,
                'failures' => $verification['failures'] ?? 0,
                'errors' => $verification['errors'] ?? 0,
                'skipped' => $verification['skipped'] ?? 0,
                'junit' => $verification['junit'] ?? null,
                'junit_digest' => $this->digest($verification['junit'] ?? null),
                'failing_tests' => array_map(
                    fn (array $test): array => array_intersect_key($test, array_flip(['name', 'file', 'kind', 'type'])),
                    array_slice($verification['failing_tests'] ?? [], 0, 20),
                ),
            ];
        } catch (Throwable $exception) {
            $baseline = ['classification' => 'not_recorded', 'reason' => $exception->getMessage()];
        }

        $baseline = [...$baseline, 'test_path' => $task->test_path, 'test_digest' => $task->test_digest, 'recorded_at' => $recordedAt];

        $source = $task->source ?? [];
        $source['test_lock']['red_baseline'] = $baseline;
        $task->update(['source' => $source]);

        return $baseline;
    }

    /**
     * @param  array<string, mixed>  $verification
     * @return array{classification: string, reason: string}
     */
    private function classify(array $verification): array
    {
        $reason = is_string($verification['reason'] ?? null) ? $verification['reason'] : null;
        if (($verification['status'] ?? null) === 'passed') {
            return ['classification' => 'already_passing', 'reason' => 'The locked test passed before implementation.'];
        }
        if ($reason !== 'tests_failed') {
            return ['classification' => 'bootstrap_error', 'reason' => $reason ?? 'unknown'];
        }
        if (($verification['identified_required_test'] ?? false) !== true) {
            return ['classification' => 'bootstrap_error', 'reason' => 'required_test_not_identified'];
        }
        if (preg_match('/PHP (Parse|Fatal) error/i', (string) ($verification['output'] ?? '')) === 1) {
            return ['classification' => 'bootstrap_error', 'reason' => 'php_fatal_error'];
        }
        foreach ($verification['failing_tests'] ?? [] as $test) {
            if ($this->isBootstrapError($test)) {
                return ['classification' => 'bootstrap_error', 'reason' => 'test_bootstrap_error'];
            }
        }

        return ['classification' => 'missing_behavior', 'reason' => 'tests_failed'];
    }

    /**
     * Assertion failures and missing application classes mean the behavior is
     * not built yet. Parse errors, fatal errors, and missing test framework
     * classes mean the test itself cannot run.
     *
     * @param  array<string, string>  $test
     */
    private function isBootstrapError(array $test): bool
    {
        if (($test['kind'] ?? null) !== 'error') {
            return false;
        }
        $type = ltrim((string) ($test['type'] ?? ''), '\\');
        $message = (string) ($test['message'] ?? '');
        if (in_array($type, ['ParseError', 'CompileError'], true)
            || preg_match('/syntax error|Cannot redeclare|Call to undefined function (it|test|expect|describe|beforeEach|uses|pest)\(/i', $message) === 1) {
            return true;
        }

        return preg_match('/(Class|Interface|Trait) "\\\\?(Tests|PHPUnit|Pest)\\\\[^"]*" not found/', $message) === 1;
    }

    private function digest(mixed $path): ?string
    {
        if (! is_string($path) || is_link($path) || ! is_file($path)) {
            return null;
        }

        return hash_file('sha256', $path) ?: null;
    }
}
