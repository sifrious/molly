<?php

namespace Sifrious\Molly\Actions;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Dotenv\Dotenv;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Sifrious\Molly\Execution\Sandbox;
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
            $sandbox = app(Sandbox::class);
            $timeout = max(1, min(3600, (int) config('molly.test_timeout', 120)));
            $env = $this->workspaceEnvironment();
            if ($sandbox->available() && ! $sandbox->allowUnsafe()) {
                $sandboxed = $sandbox->run(
                    $workspace,
                    [$evidenceDirectory],
                    $command,
                    $evidenceDirectory,
                    $timeout,
                    $env,
                );
                $report['output'] = $sandboxed['output'].$sandboxed['error'];
                $successful = ! $sandboxed['timed_out'] && $sandboxed['exit_code'] === 0;
                if ($sandboxed['timed_out']) {
                    return [...$report, 'reason' => 'test_timeout'];
                }
            } else {
                $result = Process::path($workspace)
                    ->env($env)
                    ->timeout($timeout)
                    ->run($command);
                $report['output'] = $result->output().$result->errorOutput();
                $successful = $result->successful();
            }
        } catch (ProcessTimedOutException $exception) {
            return [...$report, 'reason' => 'test_timeout', 'output' => $exception->result->output().$exception->result->errorOutput()];
        } catch (Throwable $exception) {
            return [...$report, 'reason' => 'test_process_failed', 'output' => $exception->getMessage()];
        }

        $counts = $this->readEvidence($junit, $workspace, $testPath);
        $report = [...$report, ...$counts];

        $reason = match (true) {
            isset($counts['reason']) => $counts['reason'],
            $report['tests'] === 0 => 'no_tests',
            $report['failures'] > 0 || $report['errors'] > 0 => 'tests_failed',
            $report['skipped'] > 0 => 'tests_skipped_or_incomplete',
            $report['assertions'] === 0 => 'no_assertions',
            ! $successful => 'test_process_failed',
            ($report['identified_required_test'] ?? false) !== true => 'false_green',
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
     * @return array{reason: string}|array{tests: int, assertions: int, failures: int, errors: int, skipped: int, identified_required_test: bool}
     */
    private function readEvidence(string $path, string $workspace, string $testPath): array
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

        return [...$counts, 'identified_required_test' => $this->identifiesRequiredTest($xpath, $workspace, $testPath)];
    }

    private function identifiesRequiredTest(DOMXPath $xpath, string $workspace, string $testPath): bool
    {
        $required = str_replace('\\', '/', $testPath);
        $workspace = rtrim(str_replace('\\', '/', $workspace), '/');
        $absolute = $workspace.'/'.ltrim($required, '/');
        $directory = is_dir($absolute);
        $names = $this->expectedTestNames($required);

        foreach ($xpath->query('//testcase | //testsuite') as $node) {
            $file = str_replace('\\', '/', $node->getAttribute('file'));
            $class = str_replace('\\', '/', str_replace('.', '/', $node->getAttribute('class') !== '' ? $node->getAttribute('class') : $node->getAttribute('classname')));
            if ($this->matchesRequiredPath($file, $required, $absolute, $directory, $names)
                || $this->matchesRequiredClass($class, $names, $directory)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function expectedTestNames(string $required): array
    {
        $base = preg_replace('/\.php$/i', '', basename($required)) ?? basename($required);
        $names = [$base];
        if (str_ends_with($base, 'Test') && strlen($base) > 4) {
            $names[] = substr($base, 0, -4);
        }

        return $names;
    }

    /** @param  list<string>  $names */
    private function matchesRequiredPath(string $file, string $required, string $absolute, bool $directory, array $names): bool
    {
        if ($file === '') {
            return false;
        }

        $path = explode('::', $file, 2)[0];
        if ($path === $required || $path === $absolute || str_ends_with($path, '/'.$required)) {
            return true;
        }
        if ($directory && (str_starts_with($path, $absolute.'/') || str_starts_with($path, rtrim($required, '/').'/'))) {
            return true;
        }

        return ! str_contains($path, '/') && ($directory || in_array($path, $names, true));
    }

    /** @param  list<string>  $names */
    private function matchesRequiredClass(string $class, array $names, bool $directory): bool
    {
        if ($class === '') {
            return false;
        }

        return $directory || in_array(basename($class), $names, true);
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
