<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Workspace;

beforeEach(function (): void {
    $this->evidenceWorkspace = sys_get_temp_dir().'/molly-integrity-'.bin2hex(random_bytes(8));
    mkdir($this->evidenceWorkspace.'/vendor/bin', 0700, true);
    mkdir($this->evidenceWorkspace.'/app');
    touch($this->evidenceWorkspace.'/vendor/bin/pest');
    file_put_contents($this->evidenceWorkspace.'/app/Example.php', '<?php return false;');
    writeProtectedTest($this->evidenceWorkspace, 'tests/ExampleTest.php');
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->evidenceWorkspace);
});

it('rejects inconsistent suite totals even when testcase counts match the outer suite', function (string $xml): void {
    Process::fake(function (PendingProcess $process) use ($xml) {
        file_put_contents($process->command[array_search('--log-junit', $process->command, true) + 1], $xml);

        return Process::result();
    });

    $result = app(VerifyChanges::class)->handle($this->evidenceWorkspace, 'tests/ExampleTest.php', $this->evidenceWorkspace.'/evidence');

    expect($result)->toMatchArray(['status' => 'failed', 'reason' => 'junit_invalid']);
})->with([
    'nested tests disagree' => '<testsuite tests="1"><testsuite tests="2"><testcase assertions="1"/></testsuite></testsuite>',
    'nested failures disagree' => '<testsuite tests="1" failures="0"><testsuite tests="1" failures="1"><testcase assertions="1"/></testsuite></testsuite>',
    'root tests disagree' => '<testsuites tests="2"><testsuite tests="1"><testcase assertions="1"/></testsuite></testsuites>',
]);

it('rejects a fresh JUnit path that links to evidence from an earlier run', function (): void {
    $old = $this->evidenceWorkspace.'/old.xml';
    $xml = '<testsuite tests="1" assertions="1"><testcase assertions="1"/></testsuite>';
    file_put_contents($old, $xml);
    Process::fake(function (PendingProcess $process) use ($old) {
        symlink($old, $process->command[array_search('--log-junit', $process->command, true) + 1]);

        return Process::result();
    });

    $result = app(VerifyChanges::class)->handle($this->evidenceWorkspace, 'tests/ExampleTest.php', $this->evidenceWorkspace.'/evidence');

    expect($result)->toMatchArray(['status' => 'failed', 'reason' => 'junit_invalid'])
        ->and(file_get_contents($old))->toBe($xml);
});

it('rejects contradictory or malformed review findings at the completion boundary', function (string $defect): void {
    $review = [
        'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'The function uses existing code.']),
        'findings' => [],
    ];
    $finding = ['code' => 'E', 'classification' => 'accidental', 'severity' => 'warning', 'path' => 'app/Example.php', 'line' => 1, 'problem' => 'Unused wrapper.', 'recommendation' => 'Remove the wrapper.'];
    $review['checks']['E']['status'] = 'findings';
    $review['findings'] = [$finding];
    switch ($defect) {
        case 'unreported finding':
            $review['checks']['E']['status'] = 'clean';
            break;
        case 'missing finding':
            $review['findings'] = [];
            break;
        case 'unknown severity':
            $review['findings'][0]['severity'] = 'blocker';
            break;
        case 'missing explanation':
            unset($review['findings'][0]['problem']);
            break;
        case 'keyed findings':
            $review['findings'] = ['first' => $finding];
            break;
    }

    expect(app(ReviewChanges::class)->passed($review))->toBeFalse();
})->with(['unreported finding', 'missing finding', 'unknown severity', 'missing explanation', 'keyed findings']);

it('checks warning locations against the reviewed snapshot before accepting completion', function (string $path, int $line, bool $accepted): void {
    $review = [
        'checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'The function uses existing code.']),
        'findings' => [['code' => 'F', 'classification' => 'pragmatic', 'severity' => 'warning', 'path' => $path, 'line' => $line, 'problem' => 'The function is long.', 'recommendation' => 'Keep the related steps together.']],
    ];
    $review['checks']['F']['status'] = 'findings';

    expect(app(ReviewChanges::class)->passed($review, ['app/Example.php' => "<?php\nreturn true;"]))->toBe($accepted);
})->with([
    'valid warning' => ['app/Example.php', 2, true],
    'different file' => ['app/Other.php', 2, false],
    'beyond the reviewed contents' => ['app/Example.php', 3, false],
]);

it('refuses completion when review evidence names a file outside the actual run snapshot', function (): void {
    $this->mock(GenerateChanges::class)->shouldReceive('handle')->once()->andReturn([
        'summary' => 'Add the example.', 'files' => [['path' => 'app/Example.php', 'content' => '<?php return true;']],
    ]);
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1]);
    $checks = array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'The function uses existing code.']);
    $checks['F']['status'] = 'findings';
    $this->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle')->once()->andReturn([
        'checks' => $checks,
        'findings' => [['code' => 'F', 'classification' => 'pragmatic', 'severity' => 'warning', 'path' => 'app/Other.php', 'line' => 1, 'problem' => 'The function is long.', 'recommendation' => 'Keep the related steps together.']],
    ]);

    $run = app(RunTask::class)->handle('Add the example.', $this->evidenceWorkspace, ['app/Example.php'], 'tests/ExampleTest.php');

    expect($run->status)->toBe('failed')
        ->and($run->report['verification']['status'])->toBe('passed')
        ->and($run->report['review']['findings'][0]['path'])->toBe('app/Other.php');
});

it('rejects a substituted symlink before writing any selected file', function (): void {
    $first = $this->evidenceWorkspace.'/app/First.php';
    $second = $this->evidenceWorkspace.'/app/Second.php';
    $outside = $this->evidenceWorkspace.'/outside.php';
    file_put_contents($first, 'first original');
    file_put_contents($second, 'second original');
    file_put_contents($outside, 'outside original');
    $workspace = new Workspace($this->evidenceWorkspace);
    $before = $workspace->read(['app/First.php', 'app/Second.php']);
    unlink($second);
    symlink($outside, $second);

    expect(fn () => $workspace->apply([
        ['path' => 'app/First.php', 'content' => 'first proposal'],
        ['path' => 'app/Second.php', 'content' => 'second proposal'],
    ], $before))->toThrow(RuntimeException::class, 'PATH_INVALID');
    expect(file_get_contents($first))->toBe('first original')
        ->and(file_get_contents($outside))->toBe('outside original')
        ->and(is_link($second))->toBeTrue();
});
