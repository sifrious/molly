<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\File;
use Sifrious\Molly\Verification\FalseGreenVerifier;
use Sifrious\Molly\Verification\VerificationState;
use Throwable;

/**
 * Opt-in bounded negative-control probes around Pest.
 * Mutates copies in-place under guaranteed rollback; never leaves the canonical tree dirty.
 */
final class DetectFalseGreen implements FalseGreenVerifier
{
    public function __construct(private VerifyChanges $verify) {}

    public function handle(string $workspace, string $testPath, array $mutablePaths, string $evidenceDirectory): array
    {
        $budgets = [
            'max_mutations' => $this->maxMutations(),
            'timeout_seconds' => $this->timeoutSeconds(),
            'mutations_attempted' => 0,
            'elapsed_ms' => 0,
        ];

        if (! $this->enabled()) {
            return [
                'status' => 'not_run',
                'state' => VerificationState::NotRun->value,
                'conclusion' => 'disabled',
                'test_path' => $testPath,
                'probes' => [],
                'reason' => 'False-green detection is disabled for this profile.',
                'budgets' => $budgets,
            ];
        }

        $paths = array_values(array_unique(array_filter($mutablePaths, fn ($path): bool => is_string($path) && $path !== '')));
        $paths = array_slice($paths, 0, $budgets['max_mutations']);
        if ($paths === []) {
            return [
                'status' => 'inconclusive',
                'state' => VerificationState::ReviewRequired->value,
                'conclusion' => 'no_mutable_targets',
                'test_path' => $testPath,
                'probes' => [],
                'reason' => 'No mutable production files were supplied for a negative-control probe.',
                'budgets' => $budgets,
            ];
        }

        $started = hrtime(true);
        $deadline = $started + ($budgets['timeout_seconds'] * 1_000_000_000);
        $originals = [];
        $probes = [];

        try {
            foreach ($paths as $relative) {
                if (hrtime(true) >= $deadline) {
                    return $this->inconclusive('timeout', $testPath, $probes, $budgets, $started, 'False-green probe budget exhausted before all mutations ran.');
                }

                $absolute = rtrim($workspace, '/').'/'.$relative;
                if (! is_file($absolute) || is_link($absolute)) {
                    $probes[] = [
                        'path' => $relative,
                        'mutation' => 'replace_php_body_with_throwing_stub',
                        'outcome' => 'skipped_missing_file',
                        'pest_status' => null,
                    ];
                    continue;
                }

                $before = File::get($absolute);
                $originals[$absolute] = $before;
                $mutation = $this->mutationStub($before);
                File::put($absolute, $mutation);
                $budgets['mutations_attempted']++;

                $probeEvidence = rtrim($evidenceDirectory, '/').'/false-green-'.$budgets['mutations_attempted'];
                File::ensureDirectoryExists($probeEvidence, 0700);

                $previousTimeout = config('molly.test_timeout');
                config(['molly.test_timeout' => min((int) $previousTimeout, $budgets['timeout_seconds'])]);
                try {
                    $result = $this->verify->handle($workspace, $testPath, $probeEvidence);
                } catch (Throwable $exception) {
                    $this->restore($originals);
                    $originals = [];

                    return $this->inconclusive(
                        'probe_error',
                        $testPath,
                        [...$probes, [
                            'path' => $relative,
                            'mutation' => 'replace_php_body_with_throwing_stub',
                            'mutation_digest' => hash('sha256', $mutation),
                            'outcome' => 'error',
                            'pest_status' => null,
                            'error' => $exception->getMessage(),
                        ]],
                        $budgets,
                        $started,
                        'False-green probe errored: '.$exception->getMessage(),
                    );
                } finally {
                    config(['molly.test_timeout' => $previousTimeout]);
                    File::put($absolute, $before);
                    unset($originals[$absolute]);
                }

                $stayedGreen = ($result['status'] ?? null) === 'passed';
                $probes[] = [
                    'path' => $relative,
                    'mutation' => 'replace_php_body_with_throwing_stub',
                    'mutation_digest' => hash('sha256', $mutation),
                    'outcome' => $stayedGreen ? 'stayed_green' : 'failed_as_expected',
                    'pest_status' => $result['status'] ?? null,
                    'pest_reason' => $result['reason'] ?? null,
                    'tests' => $result['tests'] ?? null,
                    'assertions' => $result['assertions'] ?? null,
                ];

                if ($stayedGreen) {
                    $budgets['elapsed_ms'] = (int) ((hrtime(true) - $started) / 1_000_000);

                    return [
                        'status' => 'false_green',
                        'state' => VerificationState::Fail->value,
                        'conclusion' => 'weak_test',
                        'test_path' => $testPath,
                        'probes' => $probes,
                        'reason' => 'Selected Pest test stayed green after protected behavior in '.$relative.' was broken.',
                        'budgets' => $budgets,
                    ];
                }
            }

            $budgets['elapsed_ms'] = (int) ((hrtime(true) - $started) / 1_000_000);
            if ($budgets['mutations_attempted'] === 0) {
                return $this->inconclusive('no_mutations_applied', $testPath, $probes, $budgets, $started, 'No mutations could be applied.');
            }

            return [
                'status' => 'meaningful',
                'state' => VerificationState::Pass->value,
                'conclusion' => 'test_failed_under_mutation',
                'test_path' => $testPath,
                'probes' => $probes,
                'reason' => 'Breaking protected behavior caused the selected Pest test to fail.',
                'budgets' => $budgets,
            ];
        } finally {
            $this->restore($originals);
        }
    }

    public function enabled(): bool
    {
        return (bool) config('molly.false_green.enabled', false);
    }

    private function maxMutations(): int
    {
        $value = (int) config('molly.false_green.max_mutations', 2);

        return max(1, min(5, $value));
    }

    private function timeoutSeconds(): int
    {
        $value = (int) config('molly.false_green.timeout_seconds', 30);

        return max(5, min(120, $value));
    }

    private function mutationStub(string $original): string
    {
        return "<?php\n\nthrow new \\RuntimeException('MOLLY_FALSE_GREEN_PROBE');\n";
    }

    /** @param  array<string, string>  $originals */
    private function restore(array $originals): void
    {
        foreach ($originals as $path => $contents) {
            File::put($path, $contents);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $probes
     * @param  array<string, int>  $budgets
     * @return array<string, mixed>
     */
    private function inconclusive(string $conclusion, string $testPath, array $probes, array $budgets, int $started, string $reason): array
    {
        $budgets['elapsed_ms'] = (int) ((hrtime(true) - $started) / 1_000_000);

        return [
            'status' => 'inconclusive',
            'state' => VerificationState::ReviewRequired->value,
            'conclusion' => $conclusion,
            'test_path' => $testPath,
            'probes' => $probes,
            'reason' => $reason,
            'budgets' => $budgets,
        ];
    }
}
