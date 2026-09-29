<?php

/*
 * Negative controls for bin/molly-acceptance-gate (MME-5885 M11.6 to M11.10).
 *
 * Each test builds a complete, passing evidence set for the frozen manifest,
 * checks that the gate accepts it, breaks one thing, checks that the gate rejects
 * the right subcase with the right reason code, then restores and checks that
 * the gate accepts again.
 */

use Symfony\Component\Process\Process;

const GATE_COMMIT = 'bef10d0cce9bd39f51b94d719518c280dbbe0143';
const GATE_BUILT_AT = '2026-09-27T12:00:00Z';
const GATE_RECORDED_AT = '2026-09-27T13:00:00Z';

function gateRoot(): string
{
    return dirname(__DIR__, 2);
}

function gateManifest(): array
{
    return json_decode(file_get_contents(gateRoot().'/docs/acceptance/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
}

function gateWriteJson(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

function gateRemoveTree(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

/**
 * Write a candidate and a complete evidence set where every manifest subcase passes.
 *
 * @return array{root: string, manifest: string, candidate: string, evidence: string}
 */
function gateFixture(): array
{
    $root = sys_get_temp_dir().'/molly-gate-'.bin2hex(random_bytes(6));
    mkdir($root.'/candidate', 0755, true);
    mkdir($root.'/evidence', 0755, true);

    $artifact = 'sifrious-molly-'.substr(GATE_COMMIT, 0, 8).'.zip';
    file_put_contents($root.'/candidate/'.$artifact, 'fixture artifact bytes');
    $artifactSha = hash_file('sha256', $root.'/candidate/'.$artifact);
    gateWriteJson($root.'/candidate/candidate.json', [
        'schema' => 'molly.acceptance-candidate/1',
        'commit' => GATE_COMMIT,
        'version' => '0.2.0-RC1',
        'artifact' => $artifact,
        'sha256' => $artifactSha,
        'built_at' => GATE_BUILT_AT,
        'builder' => ['script' => 'bin/molly-candidate'],
    ]);

    $records = [];
    foreach (gateManifest()['criteria'] as $criterion) {
        foreach ($criterion['subcases'] as $subcase) {
            $files = [];
            foreach (array_map('trim', explode(',', $subcase['evidence'])) as $name) {
                $path = $subcase['id'].'/'.(str_ends_with($name, '/') ? $name.'evidence.txt' : $name);
                is_dir(dirname($root.'/evidence/'.$path)) || mkdir(dirname($root.'/evidence/'.$path), 0755, true);
                // The gate reads every listed .xml file as junit, so junit evidence holds three passing tests.
                file_put_contents($root.'/evidence/'.$path, str_ends_with($name, '.xml')
                    ? gateJunit(array_map(fn (int $n) => ['tests/Feature/ExampleTest.php', "it passes check {$n} for {$subcase['id']}", false], [1, 2, 3]))
                    : "evidence for {$subcase['id']}\n");
                $files[] = ['path' => $path, 'sha256' => hash_file('sha256', $root.'/evidence/'.$path)];
            }
            $records[] = [
                'subcase' => $subcase['id'],
                'outcome' => 'PASS',
                'mode' => $subcase['mode'],
                'candidate_sha' => GATE_COMMIT,
                'artifact_sha256' => $artifactSha,
                'lock_sha256' => hash('sha256', 'composer.lock'),
                'timestamp' => GATE_RECORDED_AT,
                'command' => "run check for {$subcase['id']}",
                'exit_code' => $subcase['expected_exit_code'] ?? 0,
                'counts' => ['discovered' => 3, 'passed' => 3, 'failed' => 0, 'skipped' => 0],
                'files' => $files,
                'intervention' => null,
                'protected_digest_expected' => hash('sha256', 'protected tests'),
                'protected_digest_observed' => hash('sha256', 'protected tests'),
            ];
        }
    }
    gateWriteJson($root.'/evidence/index.json', ['schema' => 'molly.acceptance-evidence/1', 'records' => $records]);

    return [
        'root' => $root,
        'manifest' => gateRoot().'/docs/acceptance/manifest.json',
        'candidate' => $root.'/candidate/candidate.json',
        'evidence' => $root.'/evidence',
    ];
}

/** @return array{exit: int, report: array, output: string} */
function gateRun(array $fixture): array
{
    $process = new Process([
        PHP_BINARY, gateRoot().'/bin/molly-acceptance-gate',
        '--manifest', $fixture['manifest'],
        '--evidence', $fixture['evidence'],
        '--candidate', $fixture['candidate'],
        '--json',
    ]);
    $process->setTimeout(60);
    $process->run();

    return [
        'exit' => $process->getExitCode(),
        'report' => json_decode($process->getOutput(), true) ?? [],
        'output' => $process->getOutput().$process->getErrorOutput(),
    ];
}

/** Change one record in index.json. */
function gateEditRecord(array $fixture, string $subcase, Closure $edit): void
{
    $path = $fixture['evidence'].'/index.json';
    $index = json_decode(file_get_contents($path), true);
    foreach ($index['records'] as $i => $record) {
        if ($record['subcase'] === $subcase) {
            $index['records'][$i] = $edit($record);
        }
    }
    gateWriteJson($path, $index);
}

/** Recompute the sha256 of every file a record lists, after a test edits one. */
function gateRehash(array $fixture, string $subcase): void
{
    gateEditRecord($fixture, $subcase, function (array $r) use ($fixture) {
        foreach ($r['files'] as $i => $file) {
            $r['files'][$i]['sha256'] = hash_file('sha256', $fixture['evidence'].'/'.$file['path']);
        }

        return $r;
    });
}

/**
 * A junit report in the shape Pest writes it.
 *
 * @param  list<array{0: string, 1: string, 2: bool}>  $cases  [file, name, skipped]
 */
function gateJunit(array $cases): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n<testsuites>\n  <testsuite name=\"Molly\" tests=\"".count($cases)."\">\n";
    foreach ($cases as [$file, $name, $skipped]) {
        $attributes = sprintf('name="%s" file="%s::%s"', htmlspecialchars($name), htmlspecialchars($file), htmlspecialchars($name));
        $xml .= $skipped ? "    <testcase {$attributes}><skipped/></testcase>\n" : "    <testcase {$attributes}/>\n";
    }

    return $xml."  </testsuite>\n</testsuites>\n";
}

/**
 * The junit cases for the tests a manifest key lists, skipped or run.
 *
 * @return list<array{0: string, 1: string, 2: bool}>
 */
function gateDeclaredCases(string $key, bool $skipped): array
{
    return array_map(function (string $test) use ($skipped): array {
        [$file, $name] = explode(': ', $test, 2);

        return [$file, 'it '.$name, $skipped];
    }, gateManifest()['not_applicable'][$key]['tests']);
}

const GATE_REMOTE_CLIENT_CASE = ['tests/Feature/WebTasksTest.php', 'it rejects remote clients and non-loopback hosts with data set "dataset "remote client""', false];

/**
 * Give a record the junit files the RC10 M10.3 runs produced, with every skip
 * declared: macOS skips the Landlock and fallback tests, and Linux on PHP 8.3 skips
 * the two-worker and fallback tests. Without macOS, the record holds only the Linux
 * run, as a tests.yml lane does.
 */
function gateDeclaredSkips(array $fixture, string $subcase = 'M10.3', bool $macos = true): void
{
    $runs = array_filter([
        $subcase.'/junit-macos.xml' => $macos ? ['Darwin', '8.4.23', [
            GATE_REMOTE_CLIENT_CASE,
            ...gateDeclaredCases('landlock-linux-only', true),
            ...gateDeclaredCases('laravel-ai-pre-v1-fallback', true),
            ...gateDeclaredCases('sqlite-competing-workers-php84', false),
        ]] : null,
        $subcase.'/linux-8.3/junit.xml' => ['Linux', '8.3.35', [
            GATE_REMOTE_CLIENT_CASE,
            ...gateDeclaredCases('landlock-linux-only', false),
            ...gateDeclaredCases('laravel-ai-pre-v1-fallback', true),
            ...gateDeclaredCases('sqlite-competing-workers-php84', true),
        ]],
    ]);
    $counts = ['discovered' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0];
    foreach ($runs as $path => [, , $cases]) {
        is_dir(dirname($fixture['evidence'].'/'.$path)) || mkdir(dirname($fixture['evidence'].'/'.$path), 0755, true);
        file_put_contents($fixture['evidence'].'/'.$path, gateJunit($cases));
        $skipped = count(array_filter($cases, fn (array $case) => $case[2]));
        $counts['discovered'] += count($cases);
        $counts['skipped'] += $skipped;
        $counts['passed'] += count($cases) - $skipped;
    }
    gateEditRecord($fixture, $subcase, function (array $r) use ($runs, $counts, $macos) {
        foreach ($runs as $path => [$os, $php]) {
            $r['files'][] = ['path' => $path, 'sha256' => ''];
            $r['test_runs'][] = ['junit' => $path, 'os_family' => $os, 'php' => $php];
        }
        $r['counts'] = $counts;
        $r['skip_reasons'] = [...($macos ? ['landlock-linux-only'] : []), 'laravel-ai-pre-v1-fallback', 'sqlite-competing-workers-php84'];

        return $r;
    });
    gateRehash($fixture, $subcase);
}

/** @return list<array{subcase: string, code: string}> */
function gateRejections(array $report): array
{
    $found = [];
    foreach ($report['criteria'] ?? [] as $criterion) {
        foreach ($criterion['subcases'] as $row) {
            foreach ($row['reasons'] as $reason) {
                $found[] = ['subcase' => $row['id'], 'code' => $reason['code']];
            }
        }
    }

    return $found;
}

/**
 * Build the fixture and apply $prepare, accept it, apply the mutation, expect the
 * given rejection, restore the saved index and files, and expect acceptance again.
 */
function gateControl(Closure $mutate, string $subcase, string $code, string $criterion, ?Closure $prepare = null): void
{
    $fixture = gateFixture();
    try {
        if ($prepare !== null) {
            $prepare($fixture);
        }
        $green = gateRun($fixture);
        expect($green['exit'])->toBe(0, $green['output'])
            ->and($green['report']['verdict'])->toBe('ACCEPTED');

        $backup = $fixture['root'].'/evidence-backup';
        (new Process(['cp', '-R', $fixture['evidence'], $backup]))->mustRun();

        $mutate($fixture);
        $red = gateRun($fixture);
        expect($red['exit'])->toBe(1, $red['output'])
            ->and($red['report']['verdict'])->toBe('REJECTED')
            ->and(gateRejections($red['report']))->toContain(['subcase' => $subcase, 'code' => $code])
            ->and($red['report']['criteria'][$criterion]['status'])->toBe('REJECTED');

        gateRemoveTree($fixture['evidence']);
        rename($backup, $fixture['evidence']);
        $restored = gateRun($fixture);
        expect($restored['exit'])->toBe(0, $restored['output'])
            ->and($restored['report']['verdict'])->toBe('ACCEPTED');
    } finally {
        gateRemoveTree($fixture['root']);
    }
}

it('accepts a complete evidence set for every manifest criterion', function () {
    $fixture = gateFixture();
    try {
        $result = gateRun($fixture);
        $manifest = gateManifest();

        expect($result['exit'])->toBe(0, $result['output'])
            ->and($result['report']['schema'])->toBe('molly.acceptance-gate-report/1')
            ->and(array_keys($result['report']['criteria']))->toBe(array_keys($manifest['criteria']))
            ->and($result['report']['criteria']['M11']['accepted'])->toBe(10)
            ->and(gateRejections($result['report']))->toBe([]);
    } finally {
        gateRemoveTree($fixture['root']);
    }
});

it('prints a per-criterion table without --json', function () {
    $fixture = gateFixture();
    try {
        unlink($fixture['evidence'].'/M11.6/neg-6.log');
        $process = new Process([PHP_BINARY, gateRoot().'/bin/molly-acceptance-gate', '--manifest', $fixture['manifest'], '--evidence', $fixture['evidence'], '--candidate', $fixture['candidate']]);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())->toContain('M11   REJECTED  9/10')
            ->and($process->getOutput())->toContain('M01   PASS      7/7')
            ->and($process->getOutput())->toContain('M11.6   FILE_MISSING')
            ->and($process->getOutput())->toContain('Verdict: REJECTED');
    } finally {
        gateRemoveTree($fixture['root']);
    }
});

test('M11.6 rejects a missing evidence file', function () {
    gateControl(fn (array $f) => unlink($f['evidence'].'/M11.6/neg-6.log'), 'M11.6', 'FILE_MISSING', 'M11');
});

test('M11.6 rejects evidence the manifest names but the record does not list', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M02.1', function (array $r) {
        $r['files'] = array_slice($r['files'], 0, 1);

        return $r;
    }), 'M02.1', 'FILE_MISSING', 'M02');
});

test('M11.6 rejects a subcase with no record', function () {
    gateControl(function (array $f) {
        $path = $f['evidence'].'/index.json';
        $index = json_decode(file_get_contents($path), true);
        $index['records'] = array_values(array_filter($index['records'], fn ($r) => $r['subcase'] !== 'M11.6'));
        gateWriteJson($path, $index);
    }, 'M11.6', 'MISSING_RECORD', 'M11');
});

test('M11.7 rejects a skipped mandatory test', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M11.7', function (array $r) {
        $r['counts'] = ['discovered' => 3, 'passed' => 2, 'failed' => 0, 'skipped' => 1];

        return $r;
    }), 'M11.7', 'UNDECLARED_SKIP', 'M11');
});

test('M11.8 rejects a record made against a different candidate commit', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M11.8', function (array $r) {
        $r['candidate_sha'] = str_repeat('a', 40);

        return $r;
    }), 'M11.8', 'CANDIDATE_SHA_MISMATCH', 'M11');
});

test('M11.8 rejects a record made against a different artifact', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M11.8', function (array $r) {
        $r['artifact_sha256'] = str_repeat('b', 64);

        return $r;
    }), 'M11.8', 'ARTIFACT_SHA_MISMATCH', 'M11');
});

test('M11.9 rejects zero discovered tests', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M11.9', function (array $r) {
        $r['counts'] = ['discovered' => 0, 'passed' => 0, 'failed' => 0, 'skipped' => 0];

        return $r;
    }), 'M11.9', 'ZERO_TESTS', 'M11');
});

test('M11.10 rejects a changed protected test digest', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M11.10', function (array $r) {
        $r['protected_digest_observed'] = hash('sha256', 'edited protected test');

        return $r;
    }), 'M11.10', 'PROTECTED_DIGEST_CHANGED', 'M11');
});

it('rejects evidence recorded before the candidate was built', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M06.6', function (array $r) {
        $r['timestamp'] = '2026-09-27T11:59:59Z';

        return $r;
    }), 'M06.6', 'STALE_EVIDENCE', 'M06');
});

it('rejects an evidence file whose checksum no longer matches', function () {
    gateControl(fn (array $f) => file_put_contents($f['evidence'].'/M10.4/molly-ui/evidence.txt', "edited\n"), 'M10.4', 'CHECKSUM_MISMATCH', 'M10');
});

it('rejects failed tests reported as PASS', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M06.6', function (array $r) {
        $r['counts'] = ['discovered' => 3, 'passed' => 2, 'failed' => 1, 'skipped' => 0];

        return $r;
    }), 'M06.6', 'FAILED_BUT_PASS', 'M06');
});

it('rejects an outcome outside PASS, FAIL, BLOCKED, and UNVERIFIED', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M03.1', function (array $r) {
        $r['outcome'] = 'OK';

        return $r;
    }), 'M03.1', 'INVALID_OUTCOME', 'M03');
});

it('rejects a BLOCKED outcome', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M13.1', function (array $r) {
        $r['outcome'] = 'BLOCKED';

        return $r;
    }), 'M13.1', 'NOT_PASSED', 'M13');
});

it('rejects a record marked timed_out', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M09.8', function (array $r) {
        $r['timed_out'] = true;

        return $r;
    }), 'M09.8', 'TIMED_OUT', 'M09');
});

it('rejects a record that ran longer than the manifest timeout', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M01.1', function (array $r) {
        $r['duration_s'] = 301;

        return $r;
    }), 'M01.1', 'TIMED_OUT', 'M01');
});

it('rejects a command exit code that differs from the expected one', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M02.5', function (array $r) {
        $r['exit_code'] = 0;

        return $r;
    }), 'M02.5', 'EXIT_CODE_MISMATCH', 'M02');
});

it('ignores an expected exit code written by the record author', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M01.1', function (array $r) {
        $r['exit_code'] = 1;
        $r['expected_exit_code'] = 1;

        return $r;
    }), 'M01.1', 'EXIT_CODE_MISMATCH', 'M01');
});

it('rejects a declared skip reason used outside the subcases it applies to', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M09.13', function (array $r) {
        $r['counts'] = ['discovered' => 3, 'passed' => 2, 'failed' => 0, 'skipped' => 1];
        $r['skip_reasons'] = ['landlock-linux-only'];

        return $r;
    }), 'M09.13', 'UNDECLARED_SKIP', 'M09');
});

it('rejects a not-applicable claim the manifest did not declare', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M09.13', function (array $r) {
        $r['outcome'] = 'UNVERIFIED';
        $r['not_applicable'] = 'Not needed on this machine';

        return $r;
    }), 'M09.13', 'UNDECLARED_NOT_APPLICABLE', 'M09');
});

it('rejects an evidence path outside the evidence directory', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M05.1', function (array $r) {
        $r['files'][] = ['path' => '../candidate/candidate.json', 'sha256' => str_repeat('c', 64)];

        return $r;
    }), 'M05.1', 'UNSAFE_PATH', 'M05');
});

it('accepts a skip whose reason the manifest declared', function () {
    $fixture = gateFixture();
    try {
        gateDeclaredSkips($fixture);
        $result = gateRun($fixture);
        $record = collect(json_decode(file_get_contents($fixture['evidence'].'/index.json'), true)['records'])->firstWhere('subcase', 'M10.3');

        expect($result['exit'])->toBe(0, $result['output'])
            ->and($record['counts'])->toBe(['discovered' => 20, 'passed' => 9, 'failed' => 0, 'skipped' => 11])
            ->and(gateRejections($result['report']))->toBe([]);
    } finally {
        gateRemoveTree($fixture['root']);
    }
});

test('M11.7 rejects a mandatory test skipped in junit while the declared keys stay listed', function () {
    // RC10 M11.7 replayed this on the real M10.3 evidence and the gate accepted it.
    gateControl(function (array $f) {
        $path = $f['evidence'].'/M10.3/junit-macos.xml';
        $junit = file_get_contents($path);
        $case = sprintf('file="%s::%s"/>', GATE_REMOTE_CLIENT_CASE[0], htmlspecialchars(GATE_REMOTE_CLIENT_CASE[1]));
        expect($junit)->toContain($case);
        file_put_contents($path, str_replace($case, substr($case, 0, -2).'><skipped/></testcase>', $junit));
        gateEditRecord($f, 'M10.3', function (array $r) {
            $r['counts']['passed']--;
            $r['counts']['skipped']++;

            return $r;
        });
        gateRehash($f, 'M10.3');
    }, 'M10.3', 'UNDECLARED_SKIP', 'M10', fn (array $f) => gateDeclaredSkips($f));
});

test('M11.7 rejects declared skips when the record lists only one of their keys', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M10.3', function (array $r) {
        $r['skip_reasons'] = ['landlock-linux-only'];

        return $r;
    }), 'M10.3', 'UNDECLARED_SKIP', 'M10', fn (array $f) => gateDeclaredSkips($f));
});

it('accepts the suite skips the CI lanes of M10.1 and the whole-suite control of M11.4 declare', function () {
    $fixture = gateFixture();
    try {
        gateDeclaredSkips($fixture, 'M10.1', macos: false);
        gateDeclaredSkips($fixture, 'M11.4');
        // A negative control counts its checks, not the tests in the suite junit files it keeps.
        gateEditRecord($fixture, 'M11.4', function (array $r) {
            $r['counts'] = ['discovered' => 7, 'passed' => 7, 'failed' => 0, 'skipped' => 0];

            return $r;
        });
        $result = gateRun($fixture);

        expect($result['exit'])->toBe(0, $result['output'])
            ->and(gateRejections($result['report']))->toBe([]);
    } finally {
        gateRemoveTree($fixture['root']);
    }
});

it('rejects the same suite skips under a subcase the keys do not name', function () {
    gateControl(fn (array $f) => gateDeclaredSkips($f, 'M10.2', macos: false), 'M10.2', 'UNDECLARED_SKIP', 'M10');
});

it('rejects a PHP 8.3 skip in a run recorded on PHP 8.4', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M10.3', function (array $r) {
        $r['test_runs'][1]['php'] = '8.4.26';

        return $r;
    }), 'M10.3', 'UNDECLARED_SKIP', 'M10', fn (array $f) => gateDeclaredSkips($f));
});

it('rejects a Landlock skip in a run recorded on Linux', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M10.3', function (array $r) {
        $r['test_runs'][0]['os_family'] = 'Linux';

        return $r;
    }), 'M10.3', 'UNDECLARED_SKIP', 'M10', fn (array $f) => gateDeclaredSkips($f));
});

it('rejects a conditional skip in a junit file with no recorded environment', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M10.3', function (array $r) {
        unset($r['test_runs']);

        return $r;
    }), 'M10.3', 'UNDECLARED_SKIP', 'M10', fn (array $f) => gateDeclaredSkips($f));
});

it('rejects more skipped tests than the junit files name', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M10.3', function (array $r) {
        $r['counts']['passed']--;
        $r['counts']['skipped']++;

        return $r;
    }), 'M10.3', 'UNDECLARED_SKIP', 'M10', fn (array $f) => gateDeclaredSkips($f));
});

it('rejects fewer skipped tests than the junit files mark', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M10.3', function (array $r) {
        $r['counts']['passed']++;
        $r['counts']['skipped']--;

        return $r;
    }), 'M10.3', 'SKIP_COUNT_MISMATCH', 'M10', fn (array $f) => gateDeclaredSkips($f));
});

it('rejects a skip_reasons key the manifest declares for another subcase', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M10.3', function (array $r) {
        $r['skip_reasons'][] = 'no-application-source-index';

        return $r;
    }), 'M10.3', 'UNDECLARED_SKIP', 'M10', fn (array $f) => gateDeclaredSkips($f));
});

it('rejects test_runs that name a file the record does not list', function () {
    gateControl(fn (array $f) => gateEditRecord($f, 'M10.3', function (array $r) {
        $r['test_runs'][] = ['junit' => 'M10.3/linux-8.5/junit.xml', 'os_family' => 'Linux', 'php' => '8.5.10'];

        return $r;
    }), 'M10.3', 'MALFORMED_RECORD', 'M10', fn (array $f) => gateDeclaredSkips($f));
});

it('rejects a listed XML file the gate cannot parse for skipped tests', function () {
    gateControl(function (array $f) {
        file_put_contents($f['evidence'].'/M10.3/junit-macos.xml', '<testsuites><testsuite>');
        gateRehash($f, 'M10.3');
    }, 'M10.3', 'MALFORMED_RECORD', 'M10', fn (array $f) => gateDeclaredSkips($f));
});

it('rejects a candidate artifact that does not match candidate.json', function () {
    $fixture = gateFixture();
    try {
        $candidate = json_decode(file_get_contents($fixture['candidate']), true);
        file_put_contents(dirname($fixture['candidate']).'/'.$candidate['artifact'], 'rebuilt bytes');
        $result = gateRun($fixture);

        expect($result['exit'])->toBe(1)
            ->and(array_column($result['report']['errors'], 'code'))->toContain('CANDIDATE_ARTIFACT_MISMATCH');
    } finally {
        gateRemoveTree($fixture['root']);
    }
});

it('exits 2 when the evidence index cannot be read', function () {
    $fixture = gateFixture();
    try {
        unlink($fixture['evidence'].'/index.json');
        $result = gateRun($fixture);

        expect($result['exit'])->toBe(2)
            ->and($result['output'])->toContain('evidence index not found');
    } finally {
        gateRemoveTree($fixture['root']);
    }
});
