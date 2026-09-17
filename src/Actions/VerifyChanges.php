<?php

namespace Sifrious\Molly\Actions;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Dotenv\Dotenv;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Throwable;

class VerifyChanges
{
    /**
     * @return array{status: string, tests: int, assertions: int, failures: int, errors: int, skipped: int, output: string, command: list<string>, reason?: string, junit?: string}
     */
    public function handle(string $workspace, string $testPath, string $evidenceDirectory): array
    {
        $junit = rtrim($evidenceDirectory, '/').'/pest-'.bin2hex(random_bytes(12)).'.xml';
        $command = [PHP_BINARY, $workspace.'/vendor/bin/pest', '--colors=never', '--fail-on-empty-test-suite', '--fail-on-skipped', '--fail-on-incomplete', '--fail-on-risky', '--fail-on-warning', '--log-junit', $junit, $testPath];
        $report = ['status' => 'failed', 'tests' => 0, 'assertions' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'output' => '', 'command' => $command, 'junit' => $junit];

        if (! is_file($workspace.'/vendor/bin/pest')) {
            return [...$report, 'reason' => 'pest_missing'];
        }

        if (! is_dir($evidenceDirectory) && ! @mkdir($evidenceDirectory, 0755, true) && ! is_dir($evidenceDirectory)) {
            return [...$report, 'reason' => 'evidence_directory_unwritable'];
        }

        try {
            $result = Process::path($workspace)
                ->env($this->workspaceEnvironment())
                ->timeout(max(1, min(3600, (int) config('molly.test_timeout', 120))))
                ->run($command);
            $report['output'] = $result->output().$result->errorOutput();
        } catch (ProcessTimedOutException $exception) {
            return [...$report, 'reason' => 'test_timeout', 'output' => $exception->result->output().$exception->result->errorOutput()];
        } catch (Throwable $exception) {
            return [...$report, 'reason' => 'test_process_failed', 'output' => $exception->getMessage()];
        }

        $counts = $this->readEvidence($junit);
        $report = [...$report, ...$counts];

        $reason = match (true) {
            isset($counts['reason']) => $counts['reason'],
            $report['tests'] === 0 => 'no_tests',
            $report['failures'] > 0 || $report['errors'] > 0 => 'tests_failed',
            $report['skipped'] > 0 => 'tests_skipped_or_incomplete',
            ! $result->successful() => 'test_process_failed',
            default => null,
        };

        return $reason === null ? [...$report, 'status' => 'passed'] : [...$report, 'reason' => $reason];
    }

    /** @return array<string, false> */
    private function workspaceEnvironment(): array
    {
        $path = app()->environmentFilePath();
        if (! is_file($path)) {
            return [];
        }

        return array_fill_keys(array_keys(Dotenv::parse(file_get_contents($path))), false);
    }

    /**
     * @return array{reason: string}|array{tests: int, assertions: int, failures: int, errors: int, skipped: int}
     */
    private function readEvidence(string $path): array
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            return ['reason' => 'junit_invalid'];
        }
        if (! is_file($path) || ! is_readable($path)) {
            return ['reason' => 'junit_missing'];
        }

        $xml = file_get_contents($path);
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $xml !== false && $xml !== '' && $document->loadXML($xml, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || $document->doctype !== null || ! in_array($document->documentElement?->tagName, ['testsuites', 'testsuite'], true)) {
            return ['reason' => 'junit_invalid'];
        }

        $xpath = new DOMXPath($document);
        $counts = $this->testCounts($xpath);

        if ($counts === null || ! $this->suiteCountsMatch($xpath, $counts)) {
            return ['reason' => 'junit_invalid'];
        }

        return $counts;
    }

    /**
     * @return array{tests: int, assertions: int, failures: int, errors: int, skipped: int}|null
     */
    private function testCounts(DOMXPath $xpath, ?DOMNode $context = null): ?array
    {
        $counts = ['tests' => 0, 'assertions' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0];

        foreach ($xpath->query('.//testcase', $context) as $test) {
            $assertions = $test->getAttribute('assertions');

            if (! ctype_digit($assertions)) {
                return null;
            }

            $counts['tests']++;
            $counts['assertions'] += (int) $assertions;
            $counts['failures'] += (int) $xpath->evaluate('count(failure)', $test);
            $counts['errors'] += (int) $xpath->evaluate('count(error)', $test);
            $counts['skipped'] += (int) $xpath->evaluate('count(skipped)', $test);
        }

        return $counts;
    }

    /**
     * @param  array{tests: int, assertions: int, failures: int, errors: int, skipped: int}  $counts
     */
    private function suiteCountsMatch(DOMXPath $xpath, array $counts): bool
    {
        foreach ($xpath->query('//testsuite | /testsuites') as $suite) {
            $descendants = $this->testCounts($xpath, $suite);
            if ($descendants === null) {
                return false;
            }
            foreach ($descendants as $key => $count) {
                if (! $suite->hasAttribute($key) && ($key !== 'tests' || $suite->nodeName === 'testsuites')) {
                    continue;
                }
                if (! ctype_digit($suite->getAttribute($key)) || (int) $suite->getAttribute($key) !== $count) {
                    return false;
                }
            }
        }
        $declaredTests = 0;
        foreach ($xpath->query('/testsuite | /testsuites/testsuite') as $suite) {
            $declaredTests += (int) $suite->getAttribute('tests');
        }

        return $declaredTests === $counts['tests'];
    }
}
