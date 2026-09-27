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
                file_put_contents($root.'/evidence/'.$path, "evidence for {$subcase['id']}\n");
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
 * Accept the fixture, apply the mutation, expect exactly the given rejection,
 * restore the saved index and files, and expect acceptance again.
 */
function gateControl(Closure $mutate, string $subcase, string $code, string $criterion): void
{
    $fixture = gateFixture();
    try {
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
        gateEditRecord($fixture, 'M10.3', function (array $r) {
            $r['counts'] = ['discovered' => 3, 'passed' => 2, 'failed' => 0, 'skipped' => 1];
            $r['skip_reasons'] = ['landlock-linux-only'];

            return $r;
        });
        $result = gateRun($fixture);

        expect($result['exit'])->toBe(0, $result['output']);
    } finally {
        gateRemoveTree($fixture['root']);
    }
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
