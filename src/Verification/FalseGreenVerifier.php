<?php

namespace Sifrious\Molly\Verification;

/**
 * Second-order verifier separate from primary Pest execution.
 * Detects tests that stay green when protected behavior is broken.
 */
interface FalseGreenVerifier
{
    /**
     * @param  list<string>  $mutablePaths  Repository-relative files that define protected behavior
     * @return array{
     *   status: string,
     *   state: string,
     *   conclusion: string,
     *   test_path: string,
     *   probes: list<array<string, mixed>>,
     *   reason?: string,
     *   budgets: array{max_mutations: int, timeout_seconds: int, mutations_attempted: int, elapsed_ms: int}
     * }
     */
    public function handle(string $workspace, string $testPath, array $mutablePaths, string $evidenceDirectory): array;
}
