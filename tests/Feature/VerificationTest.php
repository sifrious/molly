<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Sifrious\Molly\Actions\VerifyChanges;

function mollyVerificationWorkspace(bool $realPest = false): string
{
    $workspace = sys_get_temp_dir().'/molly-verification-'.bin2hex(random_bytes(8));
    mkdir($workspace.'/tests', 0755, true);

    if ($realPest) {
        symlink(dirname(__DIR__, 2).'/vendor', $workspace.'/vendor');
        file_put_contents($workspace.'/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php">
    <testsuites>
        <testsuite name="Workspace">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);
    } else {
        mkdir($workspace.'/vendor/bin', 0755, true);
        touch($workspace.'/vendor/bin/pest');
    }

    return $workspace;
}

afterEach(function (): void {
    if (isset($this->workspace)) {
        File::deleteDirectory($this->workspace);
    }
});

it('verifies fresh JUnit counts and runs Pest with separate arguments and a timeout', function (): void {
    $this->workspace = mollyVerificationWorkspace();
    $testPath = 'tests/a file; echo unsafe.php';
    config(['molly.test_timeout' => 42]);
    Process::fake(function (PendingProcess $process) {
        $path = $process->command[array_search('--log-junit', $process->command, true) + 1];
        file_put_contents($path, '<testsuites><testsuite tests="2"><testsuite tests="2"><testcase assertions="1"/><testcase assertions="3"/></testsuite></testsuite></testsuites>');

        return Process::result(output: '2 tests passed');
    });

    $result = app(VerifyChanges::class)->handle($this->workspace, $testPath, $this->workspace.'/evidence');

    expect($result)->toMatchArray(['status' => 'passed', 'tests' => 2, 'assertions' => 4, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'output' => "2 tests passed\n"]);
    expect($result['junit'])->toBeFile();
    Process::assertRan(fn (PendingProcess $process): bool => $process->path === $this->workspace && $process->timeout === 42 && $process->command[0] === PHP_BINARY && end($process->command) === $testPath);
});

it('fails without usable complete JUnit evidence', function (?string $xml, string $reason): void {
    $this->workspace = mollyVerificationWorkspace();
    mkdir($this->workspace.'/evidence');
    file_put_contents($this->workspace.'/evidence/pest.xml', '<testsuite tests="1"><testcase assertions="1"/></testsuite>');
    Process::fake(function (PendingProcess $process) use ($xml) {
        if ($xml !== null) {
            file_put_contents($process->command[array_search('--log-junit', $process->command, true) + 1], $xml);
        }

        return Process::result();
    });

    $result = app(VerifyChanges::class)->handle($this->workspace, 'tests', $this->workspace.'/evidence');

    expect($result)->toMatchArray(['status' => 'failed', 'reason' => $reason]);
})->with([
    'missing fresh report' => [null, 'junit_missing'],
    'broken XML' => ['<broken', 'junit_invalid'],
    'unrelated XML' => ['<document/>', 'junit_invalid'],
    'empty suite' => ['<testsuite tests="0"/>', 'no_tests'],
    'missing testcases' => ['<testsuite tests="2"><testcase assertions="1"/></testsuite>', 'junit_invalid'],
    'inconsistent failure count' => ['<testsuite tests="1" failures="1"><testcase assertions="1"/></testsuite>', 'junit_invalid'],
    'missing assertion evidence' => ['<testsuite tests="1"><testcase/></testsuite>', 'junit_invalid'],
    'failed test' => ['<testsuite tests="1"><testcase assertions="1"><failure/></testcase></testsuite>', 'tests_failed'],
    'test error' => ['<testsuite tests="1"><testcase assertions="0"><error/></testcase></testsuite>', 'tests_failed'],
    'skipped or incomplete test' => ['<testsuite tests="1"><testcase assertions="0"><skipped/></testcase></testsuite>', 'tests_skipped_or_incomplete'],
    'entity declaration' => ['<!DOCTYPE testsuite [<!ENTITY test "unsafe">]><testsuite tests="0"/>', 'junit_invalid'],
]);

it('fails when Pest exits unsuccessfully despite passing report counts', function (): void {
    $this->workspace = mollyVerificationWorkspace();
    Process::fake(function (PendingProcess $process) {
        file_put_contents($process->command[array_search('--log-junit', $process->command, true) + 1], '<testsuite tests="1"><testcase assertions="1"/></testsuite>');

        return Process::result(errorOutput: 'A warning failed the run', exitCode: 1);
    });

    $result = app(VerifyChanges::class)->handle($this->workspace, 'tests', $this->workspace.'/evidence');

    expect($result)->toMatchArray(['status' => 'failed', 'reason' => 'test_process_failed', 'output' => "A warning failed the run\n"]);
});

it('reports missing Pest without starting a process', function (): void {
    $this->workspace = mollyVerificationWorkspace();
    unlink($this->workspace.'/vendor/bin/pest');
    Process::fake();

    $result = app(VerifyChanges::class)->handle($this->workspace, 'tests', $this->workspace.'/evidence');

    expect($result)->toMatchArray(['status' => 'failed', 'reason' => 'pest_missing']);
    Process::assertNothingRan();
});

it('runs the actual Pest binary and records real verification evidence', function (string $body, string $status): void {
    $this->workspace = mollyVerificationWorkspace(realPest: true);
    file_put_contents($this->workspace.'/tests/ExampleTest.php', '<?php use PHPUnit\\Framework\\TestCase; final class ExampleTest extends TestCase { public function test_result(): void { '.$body.' } }');

    $result = app(VerifyChanges::class)->handle($this->workspace, 'tests', $this->workspace.'/evidence');

    expect($result['status'])->toBe($status, $result['output']);
    expect($result['tests'])->toBe(1);
})->with([
    'passing' => ['$this->assertSame(2, 1 + 1);', 'passed'],
    'failing' => ['$this->assertSame(3, 1 + 1);', 'failed'],
    'skipped' => ['$this->markTestSkipped("Unavailable dependency");', 'failed'],
    'incomplete' => ['$this->markTestIncomplete("Not written");', 'failed'],
]);

it('reports a bounded test timeout with partial output', function (): void {
    $this->workspace = mollyVerificationWorkspace(realPest: true);
    config(['molly.test_timeout' => 1]);
    file_put_contents($this->workspace.'/tests/SlowTest.php', '<?php use PHPUnit\\Framework\\TestCase; final class SlowTest extends TestCase { public function test_wait(): void { sleep(5); $this->assertTrue(true); } }');

    $result = app(VerifyChanges::class)->handle($this->workspace, 'tests', $this->workspace.'/evidence');

    expect($result)->toMatchArray(['status' => 'failed', 'reason' => 'test_timeout']);
});

it('rejects a real Pest todo instead of reporting completion', function (): void {
    $this->workspace = mollyVerificationWorkspace(realPest: true);
    file_put_contents($this->workspace.'/tests/TodoTest.php', "<?php it('needs implementation')->todo();");

    $result = app(VerifyChanges::class)->handle($this->workspace, 'tests', $this->workspace.'/evidence');

    expect($result['status'])->toBe('failed', $result['output']);
});

it('reports an evidence directory that cannot be created', function (): void {
    $this->workspace = mollyVerificationWorkspace();
    file_put_contents($this->workspace.'/evidence', 'A file blocks the directory.');
    Process::fake();

    $result = app(VerifyChanges::class)->handle($this->workspace, 'tests', $this->workspace.'/evidence');

    expect($result)->toMatchArray(['status' => 'failed', 'reason' => 'evidence_directory_unwritable']);
    Process::assertNothingRan();
});
