<?php

namespace Sifrious\Molly\Actions;

use Closure;
use Sifrious\Molly\Knowledge\ComposerLock;
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
        // The next three are rewritten with the missing names by helperCause().
        'test_helper_not_imported' => [
            'The tests call Pest plugin helpers that the test file does not import.',
            'Import each Pest plugin helper the file calls, for example `use function Pest\Laravel\{get, post};`, or call the Laravel helpers on the test case, such as `$this->get(\'/\')`.',
        ],
        'test_plugin_missing' => [
            'The tests call helpers from a Pest plugin that is not installed in the workspace.',
            'The Pest plugin that defines these helpers is not installed. Call the Laravel helpers on the test case, such as `$this->get(\'/\')` and `$this->actingAs($user)`, and test Livewire components with `Livewire::test()`.',
        ],
        'test_class_not_imported' => [
            'The tests use a Laravel testing class that the test file does not import.',
            'Import each Laravel testing class the file uses with a `use` statement at the top of the file.',
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

    /**
     * Functions the Pest plugins declare in their own namespace, with the plugin's
     * Composer package. A test file must import them with `use function`.
     */
    private const PLUGIN_HELPERS = [
        'Pest\\Laravel' => ['pestphp/pest-plugin-laravel', [
            'actingAs', 'artisan', 'assertAuthenticated', 'assertAuthenticatedAs', 'assertCredentials', 'assertDatabaseCount',
            'assertDatabaseEmpty', 'assertDatabaseHas', 'assertDatabaseMissing', 'assertGuest', 'assertInvalidCredentials',
            'assertModelExists', 'assertModelMissing', 'assertNotSoftDeleted', 'assertSoftDeleted', 'be', 'call', 'castAsJson',
            'delete', 'deleteJson', 'disableCookieEncryption', 'expectsDatabaseQueryCount', 'flushHeaders', 'flushSession',
            'followingRedirects', 'forgetMock', 'freezeSecond', 'freezeTime', 'from', 'get', 'getJson', 'handleExceptions',
            'handleValidationExceptions', 'head', 'instance', 'json', 'mock', 'options', 'optionsJson', 'partialMock', 'patch',
            'patchJson', 'post', 'postJson', 'put', 'putJson', 'seed', 'spy', 'startSession', 'swap', 'travel', 'travelBack',
            'travelTo', 'withBasicAuth', 'withCookie', 'withCookies', 'withCredentials', 'withDefer', 'withExceptionHandling',
            'withHeader', 'withHeaders', 'withMiddleware', 'withMix', 'withServerVariables', 'withSession', 'withToken',
            'withUnencryptedCookie', 'withUnencryptedCookies', 'withVite', 'withoutDefer', 'withoutExceptionHandling',
            'withoutMiddleware', 'withoutMix', 'withoutMockingConsoleOutput', 'withoutToken', 'withoutVite',
        ]],
        'Pest\\Livewire' => ['pestphp/pest-plugin-livewire', ['livewire']],
    ];

    /** Laravel testing classes a test file names without a namespace, with the class it must import. */
    private const TESTING_CLASSES = [
        'DatabaseMigrations' => 'Illuminate\\Foundation\\Testing\\DatabaseMigrations',
        'DatabaseTransactions' => 'Illuminate\\Foundation\\Testing\\DatabaseTransactions',
        'DatabaseTruncation' => 'Illuminate\\Foundation\\Testing\\DatabaseTruncation',
        'LazilyRefreshDatabase' => 'Illuminate\\Foundation\\Testing\\LazilyRefreshDatabase',
        'Livewire' => 'Livewire\\Livewire',
        'RefreshDatabase' => 'Illuminate\\Foundation\\Testing\\RefreshDatabase',
        'TestCase' => 'Tests\\TestCase',
        'WithFaker' => 'Illuminate\\Foundation\\Testing\\WithFaker',
        'WithoutMiddleware' => 'Illuminate\\Foundation\\Testing\\WithoutMiddleware',
    ];

    public function __construct(private VerifyChanges $verify, private ComposerLock $lock = new ComposerLock) {}

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
        $packages = null;
        $causes = [];
        $missing = [];
        $tests = [];
        foreach ($verification['failing_tests'] ?? [] as $test) {
            if (! is_array($test)) {
                continue;
            }
            [$cause, $symbol] = $this->bootstrapCause($test, function () use (&$migrated, $workspace, $testPath): bool {
                return $migrated ??= $this->appliesDatabaseTrait($workspace, $testPath);
            }, function (string $package) use (&$packages, $workspace): bool {
                return in_array($package, $packages ??= $this->installedPackages($workspace), true);
            });
            $name = (string) ($test['name'] ?? '');
            $tests[] = ['name' => $name, 'classification' => $cause === null ? 'missing_behavior' : 'bootstrap_error', 'cause' => $cause];
            if ($cause !== null) {
                $causes[$cause][] = $name;
            }
            if ($cause !== null && $symbol !== null) {
                $missing[$cause][$symbol] = true;
            }
        }
        // A todo or skipped test beside failing ones can never pass, so the file is not a usable baseline.
        if ((int) ($verification['skipped'] ?? 0) > 0) {
            $causes['tests_skipped'] = [];
        }
        $tests = array_slice(array_values(array_unique($tests, SORT_REGULAR)), 0, 50);
        if ($causes !== []) {
            return [...$this->result('bootstrap_error', (string) array_key_first($causes), $causes, missing: array_map(array_keys(...), $missing)), 'classified_tests' => $tests];
        }

        return [...$this->result('missing_behavior', 'tests_failed'), 'classified_tests' => $tests];
    }

    /**
     * @param  array<string, list<string>>  $causes  cause => affected test names
     * @param  array<string, list<string>>  $missing  cause => the helper functions or classes the tests could not find
     * @return array{classification: string, reason: string, test_broken: bool, causes: list<array{cause: string, explanation: string, guidance: string|null, tests: list<string>}>, classified_tests: list<array{name: string, classification: string, cause: string|null}>}
     */
    private function result(string $classification, string $reason, array $causes = [], ?string $environment = null, array $missing = []): array
    {
        $listed = [];
        foreach ($causes as $cause => $tests) {
            [$explanation, $guidance] = isset($missing[$cause]) ? $this->helperCause($cause, $missing[$cause]) : self::TEST_CAUSES[$cause];
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
     * behavior, with the helper function or class the test could not find.
     * Assertion failures and missing application classes and functions mean
     * the behavior is not built yet. Parse errors, missing test framework
     * classes, Pest plugin helpers the file does not import or whose plugin
     * is not installed, an unbound TestCase, and an unmigrated database mean
     * the test itself cannot run. A missing table counts as missing behavior
     * when the test already migrates the database, because a migration is missing.
     *
     * @param  array<string, mixed>  $test
     * @param  Closure(): bool  $migrated
     * @param  Closure(string): bool  $installed  whether the workspace has a Composer package
     * @return array{0: ?string, 1: ?string}
     */
    private function bootstrapCause(array $test, Closure $migrated, Closure $installed): array
    {
        if (($test['kind'] ?? null) !== 'error') {
            return [null, null];
        }
        $type = ltrim((string) ($test['type'] ?? ''), '\\');
        $message = (string) ($test['message'] ?? '');

        if (preg_match('/Call to undefined function \\\\?(?:(Pest\\\\(?:Laravel|Livewire))\\\\)?(\w+)\(\)/', $message, $call) === 1) {
            foreach (self::PLUGIN_HELPERS as $namespace => [$package, $functions]) {
                if (($call[1] === '' || $call[1] === $namespace) && in_array($call[2], $functions, true)) {
                    $symbol = $namespace.'\\'.$call[2];

                    return [$call[1] === '' && $installed($package) ? 'test_helper_not_imported' : 'test_plugin_missing', $symbol];
                }
            }
        }
        if (preg_match('/(?:Class|Interface|Trait) "('.implode('|', array_keys(self::TESTING_CLASSES)).')" not found/', $message, $class) === 1) {
            return ['test_class_not_imported', self::TESTING_CLASSES[$class[1]]];
        }

        return [match (true) {
            in_array($type, ['ParseError', 'CompileError'], true),
            preg_match('/syntax error|Cannot redeclare/i', $message) === 1 => 'parse_error',
            preg_match('/Call to undefined function (it|test|expect|describe|beforeEach|uses|pest)\(/i', $message) === 1,
            preg_match('/(Class|Interface|Trait) "\\\\?(Tests|PHPUnit|Pest|Illuminate\\\\Foundation\\\\Testing|Illuminate\\\\Testing)\\\\[^"]*" not found/', $message) === 1 => 'test_support_missing',
            str_contains($message, 'A facade root has not been set'),
            str_contains($message, 'Did you forget to use the [pest()->extend()] function'),
            preg_match('/Call to undefined method PHPUnit\\\\Framework\\\\TestCase::\w+\(\)/', $message) === 1 => 'test_case_not_bound',
            preg_match('/no such table|Base table or view not found|relation "[^"]+" does not exist|Undefined table/i', $message) === 1 && ! $migrated() => 'database_not_migrated',
            default => null,
        }, null];
    }

    /**
     * The explanation and guidance for a missing Pest plugin helper or Laravel
     * testing class, naming each one and the exact import or replacement.
     *
     * @param  list<string>  $symbols  qualified function or class names
     * @return array{0: string, 1: string}
     */
    private function helperCause(string $cause, array $symbols): array
    {
        sort($symbols);
        $symbols = array_slice($symbols, 0, 12);
        $byNamespace = [];
        foreach ($symbols as $symbol) {
            $byNamespace[substr($symbol, 0, (int) strrpos($symbol, '\\'))][] = substr($symbol, (int) strrpos($symbol, '\\') + 1);
        }
        $calls = $this->listed(array_map(fn (string $symbol): string => substr($symbol, (int) strrpos($symbol, '\\') + 1).'()', $symbols));

        if ($cause === 'test_class_not_imported') {
            $names = $this->listed(array_map(fn (string $symbol): string => substr($symbol, (int) strrpos($symbol, '\\') + 1), $symbols));
            $imports = implode(' ', array_map(fn (string $symbol): string => '`use '.$symbol.';`', $symbols));

            return [
                'The tests use '.$names.' without importing '.(count($symbols) === 1 ? 'it' : 'them').'.',
                'The test file names '.$names.' without a `use` statement, so PHP looks for a class with no namespace. Add '.$imports.' at the top of the file.',
            ];
        }
        if ($cause === 'test_helper_not_imported') {
            $imports = [];
            foreach ($byNamespace as $namespace => $functions) {
                $imports[] = '`use function '.$namespace.'\\'.(count($functions) === 1 ? $functions[0] : '{'.implode(', ', $functions).'}').';`';
            }
            $laravel = isset($byNamespace['Pest\\Laravel']) ? ' You can call the Pest\\Laravel helpers on the test case instead, such as `$this->'.$byNamespace['Pest\\Laravel'][0].'(...)`.' : '';

            return [
                'The tests call '.$calls.', '.(count($symbols) === 1 ? 'a Pest plugin helper' : 'Pest plugin helpers').' that the test file does not import.',
                'The test file calls '.$calls.' without importing '.(count($symbols) === 1 ? 'it' : 'them').', so PHP reports "Call to undefined function". Add '.implode(' and ', $imports).' after the other `use` statements at the top of the file.'.$laravel.' Do not declare these functions in the test file.',
            ];
        }
        $packages = [];
        $replacements = [];
        foreach (array_keys($byNamespace) as $namespace) {
            $packages[] = self::PLUGIN_HELPERS[$namespace][0];
            $replacements[] = $namespace === 'Pest\\Livewire'
                ? 'test Livewire components with `Livewire::test(Component::class)` and `use Livewire\\Livewire;`'
                : 'call the Laravel helpers on the test case, such as `$this->'.$byNamespace[$namespace][0].'(...)`';
        }

        $missingPackages = implode(' and ', $packages).(count($packages) === 1 ? ' is' : ' are').' not installed in the workspace';

        return [
            'The tests call '.$calls.', but '.$missingPackages.'.',
            ucfirst($missingPackages).', so '.$calls.(count($symbols) === 1 ? ' does' : ' do').' not exist, even with `use function`. Do not import '.(count($symbols) === 1 ? 'it' : 'them').'. Instead, '.implode(', and ', $replacements).'.',
        ];
    }

    /** @param  list<string>  $items */
    private function listed(array $items): string
    {
        return count($items) < 3 ? implode(' and ', $items) : implode(', ', array_slice($items, 0, -1)).', and '.end($items);
    }

    /** @return list<string> the Composer packages composer.lock lists, and the Pest plugins under vendor/pestphp */
    private function installedPackages(string $workspace): array
    {
        try {
            $packages = array_keys($this->lock->read($workspace)['packages']);
        } catch (Throwable) {
            $packages = [];
        }
        foreach (self::PLUGIN_HELPERS as [$package]) {
            if (is_dir($workspace.'/vendor/'.$package)) {
                $packages[] = $package;
            }
        }

        return $packages;
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
