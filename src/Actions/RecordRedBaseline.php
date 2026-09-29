<?php

namespace Sifrious\Molly\Actions;

use Closure;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;
use Throwable;

/**
 * Run the locked Pest test before implementation and record why it fails.
 * Only a failure for missing behavior counts as a usable RED baseline.
 *
 * The same classification checks the test an authoring run wrote, so a test
 * that cannot run is caught before anyone is asked to lock it.
 */
class RecordRedBaseline
{
    /**
     * Causes a rewrite of the test file can fix, with a plain explanation for
     * people and Laravel and Pest guidance for the model.
     */
    private const TEST_CAUSES = [
        'database_not_migrated' => [
            'The tests query the database, but neither the test file nor tests/Pest.php applies RefreshDatabase, so the tables were never created.',
            'Tests query the database but the file does not use RefreshDatabase and tests/Pest.php does not apply it. Add `use Illuminate\Foundation\Testing\RefreshDatabase;` and `uses(RefreshDatabase::class);` to the test file.',
        ],
        'test_case_not_bound' => [
            'The tests call Laravel test helpers such as get(), post(), or actingAs(), but the file is not bound to Tests\TestCase and tests/Pest.php does not extend it.',
            'tests/Pest.php does not apply `pest()->extend(Tests\TestCase::class)` to this file, so Laravel test helpers such as get() are undefined. tests/Pest.php is read-only for you. Add `uses(Tests\TestCase::class);` to the test file, combined with any trait it needs, for example `uses(Tests\TestCase::class, RefreshDatabase::class);`.',
        ],
        'test_support_missing' => [
            'The tests use a test helper class or function that is not loaded.',
            'A test support class or function is not loaded. Import it with a `use` statement at the top of the test file, or stop depending on it. Do not reference test helpers that do not exist in the workspace.',
        ],
        'parse_error' => [
            'The test file has a PHP syntax or fatal error.',
            'The test file does not parse. Return one complete PHP file that starts with <?php and has balanced brackets, parentheses, and quotes.',
        ],
        'no_tests' => [
            'Pest found no tests in the test file.',
            'Pest found no tests in the file. Declare each case with it() or test() at the top level of the file.',
        ],
        'no_assertions' => [
            'The tests ran without making an assertion.',
            'The tests made no assertions. Each test must assert the expected behavior with expect() or a response assertion.',
        ],
        'tests_skipped' => [
            'Pest skipped the tests or marked them incomplete.',
            'Pest skipped the tests or marked them incomplete. Remove skip(), todo(), and markTestIncomplete() calls.',
        ],
    ];

    /** Traits that migrate the test database before each test. */
    private const DATABASE_TRAITS = ['RefreshDatabase', 'LazilyRefreshDatabase', 'DatabaseMigrations', 'DatabaseTruncation'];

    public function __construct(private VerifyChanges $verify) {}

    /** @return array<string, mixed> */
    public function handle(Task $task): array
    {
        return $this->record($task, $this->check($task));
    }

    /**
     * Run the task's test file and classify the result without saving it.
     *
     * @return array<string, mixed>
     */
    public function check(Task $task): array
    {
        $recordedAt = now()->toIso8601String();
        try {
            $workspace = new Workspace($task->workspace);
            $evidence = storage_path('molly/red-baselines/'.$task->id.'/'.now()->format('Ymd\THis').'-'.bin2hex(random_bytes(4)));
            $verification = $workspace->exclusively(fn (): array => $this->verify->handle($workspace->path, $task->test_path, $evidence));
            $baseline = [
                ...$this->classify($verification, $workspace->path, $task->test_path),
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

        return [...$baseline, 'test_path' => $task->test_path, 'recorded_at' => $recordedAt];
    }

    /**
     * Save a checked baseline on the task against its current test digest.
     *
     * @param  array<string, mixed>  $baseline
     * @return array<string, mixed>
     */
    public function record(Task $task, array $baseline): array
    {
        $baseline = [...$baseline, 'test_path' => $task->test_path, 'test_digest' => $task->test_digest, 'recorded_at' => $baseline['recorded_at'] ?? now()->toIso8601String()];

        $source = $task->source ?? [];
        $source['test_lock']['red_baseline'] = $baseline;
        $task->update(['source' => $source]);

        return $baseline;
    }

    /**
     * Classify a Pest run of the required test: missing_behavior,
     * already_passing, or bootstrap_error. Failing tests are classified one
     * by one from JUnit, and any test that cannot run, including a todo or
     * skipped test, makes the whole file a bootstrap_error. test_broken is
     * true when rewriting the test file can fix every cause; the causes carry
     * the affected tests, a plain explanation, and guidance for the model.
     *
     * @param  array<string, mixed>  $verification
     * @return array{classification: string, reason: string, test_broken: bool, causes: list<array{cause: string, explanation: string, guidance: string|null, tests: list<string>}>, classified_tests: list<array{name: string, classification: string, cause: string|null}>}
     */
    public function classify(array $verification, string $workspace, string $testPath): array
    {
        $reason = is_string($verification['reason'] ?? null) ? $verification['reason'] : null;
        if (($verification['status'] ?? null) === 'passed') {
            return $this->result('already_passing', 'The locked test passed before implementation.');
        }
        // PHP reports "PHP Parse error"; Pest's Collision printer shows the exception class on its own line.
        $fatal = preg_match('/PHP (Parse|Fatal) error|^\s*(ParseError|CompileError)\s*$/im', (string) ($verification['output'] ?? '')) === 1;
        if ($reason !== 'tests_failed') {
            $cause = match (true) {
                $fatal => 'parse_error',
                $reason === 'no_tests' => 'no_tests',
                $reason === 'no_assertions' => 'no_assertions',
                $reason === 'tests_skipped_or_incomplete' => 'tests_skipped',
                default => null,
            };

            return $this->result('bootstrap_error', $reason ?? 'unknown', $cause === null ? [] : [$cause => []], $cause === null ? $reason ?? 'unknown' : null);
        }
        if (($verification['identified_required_test'] ?? false) !== true) {
            return $this->result('bootstrap_error', 'required_test_not_identified', ['no_tests' => []]);
        }
        if ($fatal) {
            return $this->result('bootstrap_error', 'php_fatal_error', ['parse_error' => []]);
        }

        $migrated = null;
        $causes = [];
        $tests = [];
        foreach ($verification['failing_tests'] ?? [] as $test) {
            if (! is_array($test)) {
                continue;
            }
            $cause = $this->bootstrapCause($test, function () use (&$migrated, $workspace, $testPath): bool {
                return $migrated ??= $this->appliesDatabaseTrait($workspace, $testPath);
            });
            $name = (string) ($test['name'] ?? '');
            $tests[] = ['name' => $name, 'classification' => $cause === null ? 'missing_behavior' : 'bootstrap_error', 'cause' => $cause];
            if ($cause !== null) {
                $causes[$cause][] = $name;
            }
        }
        // A todo or skipped test beside failing ones can never pass, so the file is not a usable baseline.
        if ((int) ($verification['skipped'] ?? 0) > 0) {
            $causes['tests_skipped'] = [];
        }
        $tests = array_slice(array_values(array_unique($tests, SORT_REGULAR)), 0, 50);
        if ($causes !== []) {
            return [...$this->result('bootstrap_error', (string) array_key_first($causes), $causes), 'classified_tests' => $tests];
        }

        return [...$this->result('missing_behavior', 'tests_failed'), 'classified_tests' => $tests];
    }

    /**
     * @param  array<string, list<string>>  $causes  cause => affected test names
     * @return array{classification: string, reason: string, test_broken: bool, causes: list<array{cause: string, explanation: string, guidance: string|null, tests: list<string>}>, classified_tests: list<array{name: string, classification: string, cause: string|null}>}
     */
    private function result(string $classification, string $reason, array $causes = [], ?string $environment = null): array
    {
        $listed = [];
        foreach ($causes as $cause => $tests) {
            [$explanation, $guidance] = self::TEST_CAUSES[$cause];
            $listed[] = ['cause' => $cause, 'explanation' => $explanation, 'guidance' => $guidance, 'tests' => array_slice(array_values(array_unique($tests)), 0, 20)];
        }
        if ($environment !== null) {
            $listed[] = ['cause' => 'pest_run_failed', 'explanation' => 'Pest did not finish a usable run of the test ('.$environment.').', 'guidance' => null, 'tests' => []];
        }

        return [
            'classification' => $classification,
            'reason' => $reason,
            'test_broken' => $environment === null && $causes !== [],
            'causes' => $listed,
            'classified_tests' => [],
        ];
    }

    /**
     * Why one failing test cannot run, or null when it failed for missing
     * behavior. Assertion failures and missing application classes mean the
     * behavior is not built yet. Parse errors, missing test framework
     * classes, an unbound TestCase, and an unmigrated database mean the test
     * itself cannot run. A missing table counts as missing behavior when the
     * test already migrates the database, because a migration is missing.
     *
     * @param  array<string, mixed>  $test
     * @param  Closure(): bool  $migrated
     */
    private function bootstrapCause(array $test, Closure $migrated): ?string
    {
        if (($test['kind'] ?? null) !== 'error') {
            return null;
        }
        $type = ltrim((string) ($test['type'] ?? ''), '\\');
        $message = (string) ($test['message'] ?? '');

        return match (true) {
            in_array($type, ['ParseError', 'CompileError'], true),
            preg_match('/syntax error|Cannot redeclare/i', $message) === 1 => 'parse_error',
            preg_match('/Call to undefined function (it|test|expect|describe|beforeEach|uses|pest)\(/i', $message) === 1,
            preg_match('/(Class|Interface|Trait) "\\\\?(Tests|PHPUnit|Pest)\\\\[^"]*" not found/', $message) === 1 => 'test_support_missing',
            str_contains($message, 'A facade root has not been set'),
            str_contains($message, 'Did you forget to use the [pest()->extend()] function'),
            preg_match('/Call to undefined method PHPUnit\\\\Framework\\\\TestCase::\w+\(\)/', $message) === 1 => 'test_case_not_bound',
            preg_match('/no such table|Base table or view not found|relation "[^"]+" does not exist|Undefined table/i', $message) === 1 && ! $migrated() => 'database_not_migrated',
            default => null,
        };
    }

    /** Whether the test file or tests/Pest.php applies a trait that migrates the database. */
    private function appliesDatabaseTrait(string $workspace, string $testPath): bool
    {
        $files = new Workspace($workspace);
        foreach (array_unique([$testPath, 'tests/Pest.php']) as $path) {
            try {
                $contents = $files->readProtectedTest($path)[$path];
            } catch (Throwable) {
                continue;
            }
            if ($this->namesDatabaseTrait(token_get_all((string) $contents))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether code names a database trait outside comments and outside
     * top-level `use` imports, which name a trait without applying it.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private function namesDatabaseTrait(array $tokens): bool
    {
        $depth = 0;
        $importing = false;
        foreach ($tokens as $index => $token) {
            $text = is_array($token) ? $token[1] : $token;
            if ($importing) {
                $importing = $text !== ';';

                continue;
            }
            if ($text === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($text === '}') {
                $depth--;
            } elseif (is_array($token) && $token[0] === T_USE && $depth === 0 && $this->nextCode($tokens, $index) !== '(') {
                $importing = true;
            } elseif (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && in_array(substr((string) strrchr('\\'.$token[1], '\\'), 1), self::DATABASE_TRAITS, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<array{0: int, 1: string, 2: int}|string>  $tokens */
    private function nextCode(array $tokens, int $index): ?string
    {
        foreach (array_slice($tokens, $index + 1) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return is_array($token) ? $token[1] : $token;
            }
        }

        return null;
    }

    private function digest(mixed $path): ?string
    {
        if (! is_string($path) || is_link($path) || ! is_file($path)) {
            return null;
        }

        return hash_file('sha256', $path) ?: null;
    }
}
