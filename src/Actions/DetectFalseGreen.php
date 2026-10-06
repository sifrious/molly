<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\File;
use Sifrious\Molly\Seams\WorkspaceEvidence;
use Sifrious\Molly\Verification\FalseGreenVerifier;
use Sifrious\Molly\Verification\VerificationState;
use Sifrious\Molly\Workspace;
use Sifrious\Molly\Workspace\Directory;
use Throwable;

/**
 * Opt-in bounded negative-control probes around Pest.
 * Mutates copies in-place under guaranteed rollback; never leaves the canonical tree dirty.
 */
final class DetectFalseGreen implements FalseGreenVerifier
{
    public function __construct(private VerifyChanges $verify) {}

    /** Run a reviewed literal mutation in a disposable application copy. */
    public function targeted(string $workspace, string $testPath, array $control, string $evidenceDirectory): array
    {
        $snapshot = app(WorkspaceEvidence::class)->capture($workspace);
        $copy = sys_get_temp_dir().'/molly-seam-control-'.bin2hex(random_bytes(12));
        Directory::ensure($copy, 0700);
        $result = [];
        try {
            foreach ($snapshot['files'] as $path => $digest) {
                if ($digest === null) {
                    continue;
                }
                Directory::ensure(dirname($copy.'/'.$path), 0700);
                if (! copy($workspace.'/'.$path, $copy.'/'.$path)) {
                    throw new \RuntimeException('NEGATIVE_CONTROL_INCONCLUSIVE: Could not copy '.$path.'.');
                }
            }
            // Composer's app mappings must point at the copy. Package sources remain shared.
            Directory::ensure($copy.'/vendor', 0700);
            foreach (['autoload.php', 'composer', 'bin'] as $path) {
                if (is_dir($workspace.'/vendor/'.$path)) {
                    File::copyDirectory($workspace.'/vendor/'.$path, $copy.'/vendor/'.$path);
                } elseif (is_file($workspace.'/vendor/'.$path)) {
                    copy($workspace.'/vendor/'.$path, $copy.'/vendor/'.$path);
                }
            }
            foreach (glob($workspace.'/vendor/*') ?: [] as $path) {
                if (! in_array(basename($path), ['autoload.php', 'composer', 'bin'], true) && ! file_exists($copy.'/vendor/'.basename($path))) {
                    symlink(realpath($path), $copy.'/vendor/'.basename($path));
                }
            }
            foreach (['bootstrap/cache', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
                Directory::ensure($copy.'/'.$directory, 0700);
            }
            $clean = $this->verify->handle($copy, $testPath, $evidenceDirectory.'/control-clean');
            $result['clean_copy'] = $clean;
            if (($clean['status'] ?? null) !== 'passed') {
                $result['state'] = 'REVIEW_REQUIRED';
                $result['reason'] = 'NEGATIVE_CONTROL_INCONCLUSIVE';
            } else {
                $absolute = $copy.'/'.$control['path'];
                $before = (new Workspace($copy))->read([$control['path']])[$control['path']];
                if ($before === null || substr_count($before, $control['find']) !== 1) {
                    throw new \RuntimeException('NEGATIVE_CONTROL_INCONCLUSIVE: The reviewed mutation must match exactly once in '.$control['path'].'.');
                }
                Directory::replaceFile($absolute, str_replace($control['find'], $control['replace'], $before), 'NEGATIVE_CONTROL_INCONCLUSIVE');
                $mutated = $this->verify->handle($copy, $testPath, $evidenceDirectory.'/control-mutated');
                $classification = app(RecordRedBaseline::class)->classify($mutated, $copy, $testPath);
                $targeted = array_filter($mutated['failing_tests'] ?? [], fn (array $failure): bool => ($failure['kind'] ?? null) === 'failure'
                    && str_contains($failure['name'] ?? '', 'seam '.$control['case_id'].':'));
                $sensitive = $classification['classification'] === 'missing_behavior' && $targeted !== [] && ($mutated['errors'] ?? 0) === 0;
                $result = [...$result, 'verification' => $mutated, 'classification' => $classification,
                    'state' => $sensitive ? 'PASS' : (($mutated['status'] ?? null) === 'passed' ? 'FAIL' : 'REVIEW_REQUIRED'),
                    'reason' => $sensitive ? null : (($mutated['status'] ?? null) === 'passed' ? 'NEGATIVE_CONTROL_SURVIVED' : 'NEGATIVE_CONTROL_INCONCLUSIVE')];
            }
        } finally {
            foreach (glob($copy.'/vendor/*') ?: [] as $path) {
                if (is_link($path)) {
                    unlink($path);
                }
            }
            File::deleteDirectory($copy);
            if (file_exists($copy)) {
                throw new \RuntimeException('CLEANUP_FAILED: Could not remove '.$copy.'.');
            }
        }

        return [...$result, 'cleaned_up' => true, 'candidate_tree_digest' => $snapshot['tree_digest']];
    }

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
                Directory::ensure($probeEvidence, 0700);

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
