<?php

/*
 * bin/molly-candidate builds one immutable zip per commit and version.
 * These tests use a throwaway git repository so they do not depend on the
 * state of the working tree running the suite.
 */

use Symfony\Component\Process\Process;

function candidateRepo(): string
{
    $repo = sys_get_temp_dir().'/molly-candidate-repo-'.bin2hex(random_bytes(6));
    mkdir($repo.'/src', 0755, true);
    mkdir($repo.'/tests', 0755, true);
    mkdir($repo.'/bin', 0755, true);
    file_put_contents($repo.'/composer.json', json_encode([
        'name' => 'sifrious/molly',
        'description' => 'fixture',
        'type' => 'library',
        'autoload' => ['psr-4' => ['Sifrious\\Molly\\' => 'src/']],
        'extra' => ['laravel' => ['providers' => []]],
        'config' => new stdClass,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    file_put_contents($repo.'/.gitattributes', "/tests export-ignore\n/composer.lock export-ignore\n");
    file_put_contents($repo.'/composer.lock', "{}\n");
    file_put_contents($repo.'/src/Molly.php', "<?php\n\nclass Molly {}\n");
    file_put_contents($repo.'/src/Zeta.php', "<?php\n\nclass Zeta {}\n");
    file_put_contents($repo.'/tests/MollyTest.php', "<?php\n");
    file_put_contents($repo.'/bin/tool', "#!/bin/sh\necho ok\n");
    chmod($repo.'/bin/tool', 0755);

    foreach ([['init', '-q'], ['add', '-A'], ['commit', '-q', '-m', 'Fixture']] as $args) {
        (new Process(['git', '-c', 'user.name=Molly Test', '-c', 'user.email=molly@example.test', '-c', 'commit.gpgsign=false', ...$args], $repo))->mustRun();
    }

    return $repo;
}

function candidateHead(string $repo): string
{
    return trim((new Process(['git', 'rev-parse', 'HEAD'], $repo))->mustRun()->getOutput());
}

/** Run the builder, optionally under a different umask to prove modes are normalized. */
function candidateBuild(string $repo, string $commit, string $out, string $umask = '022'): Process
{
    $script = dirname(__DIR__, 2).'/bin/molly-candidate';
    $command = sprintf('umask %s; exec bash %s %s 0.2.0-RC1 --out %s --repo %s', $umask, escapeshellarg($script), escapeshellarg($commit), escapeshellarg($out), escapeshellarg($repo));
    $process = Process::fromShellCommandline($command, null, ['PHP_BINARY' => PHP_BINARY]);
    $process->setTimeout(120);
    $process->run();

    return $process;
}

function candidateCleanup(string ...$dirs): void
{
    foreach ($dirs as $dir) {
        (new Process(['rm', '-rf', $dir]))->run();
    }
}

it('builds the same sha256 twice for the same commit', function () {
    $repo = candidateRepo();
    $outA = $repo.'-a';
    $outB = $repo.'-b';
    try {
        $sha = candidateHead($repo);
        $first = candidateBuild($repo, $sha, $outA);
        sleep(1);
        $second = candidateBuild($repo, 'HEAD', $outB, '077');

        expect($first->getExitCode())->toBe(0, $first->getErrorOutput())
            ->and($second->getExitCode())->toBe(0, $second->getErrorOutput());

        $a = json_decode(file_get_contents($outA.'/candidate.json'), true);
        $b = json_decode(file_get_contents($outB.'/candidate.json'), true);
        $artifact = 'sifrious-molly-'.substr($sha, 0, 8).'.zip';

        expect($a['commit'])->toBe($sha)
            ->and($a['version'])->toBe('0.2.0-RC1')
            ->and($a['artifact'])->toBe($artifact)
            ->and($a['sha256'])->toBe(hash_file('sha256', $outA.'/'.$artifact))
            ->and($a['built_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and($a['builder']['script'])->toBe('bin/molly-candidate')
            ->and($b['sha256'])->toBe($a['sha256'])
            ->and(hash_file('sha256', $outB.'/'.$artifact))->toBe($a['sha256']);
    } finally {
        candidateCleanup($repo, $outA, $outB);
    }
});

it('injects version and commit into composer.json and honors export-ignore', function () {
    $repo = candidateRepo();
    $out = $repo.'-out';
    try {
        $sha = candidateHead($repo);
        candidateBuild($repo, $sha, $out)->mustRun();
        $zip = $out.'/sifrious-molly-'.substr($sha, 0, 8).'.zip';

        $entries = array_filter(array_map('trim', explode("\n", (new Process(['zipinfo', '-1', $zip]))->mustRun()->getOutput())));
        $composer = json_decode((new Process(['unzip', '-p', $zip, 'composer.json']))->mustRun()->getOutput(), true);
        $raw = (new Process(['unzip', '-p', $zip, 'composer.json']))->mustRun()->getOutput();
        $modes = (new Process(['zipinfo', $zip, 'bin/tool', 'src/Molly.php']))->mustRun()->getOutput();

        $sorted = $entries;
        sort($sorted, SORT_STRING);

        expect(array_values($entries))->toBe(array_values($sorted))
            ->and($entries)->toContain('composer.json', 'src/Molly.php', 'bin/tool')
            ->and($entries)->not->toContain('tests/MollyTest.php')
            ->and($entries)->not->toContain('composer.lock')
            ->and(array_keys($composer))->toBe(['name', 'version', 'description', 'type', 'autoload', 'extra', 'config'])
            ->and($composer['version'])->toBe('0.2.0-RC1')
            ->and($composer['extra']['molly-candidate']['commit'])->toBe($sha)
            ->and($composer['extra']['laravel'])->toBe(['providers' => []])
            ->and($raw)->toContain('"config": {}')
            ->and($modes)->toContain('-rwxr-xr-x')
            ->and($modes)->toContain('-rw-r--r--');
    } finally {
        candidateCleanup($repo, $out);
    }
});

it('refuses a dirty working tree', function () {
    $repo = candidateRepo();
    $out = $repo.'-out';
    try {
        file_put_contents($repo.'/src/Molly.php', "<?php\n\nclass Molly { }\n");
        $process = candidateBuild($repo, 'HEAD', $out);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('is dirty')
            ->and(is_dir($out))->toBeFalse();
    } finally {
        candidateCleanup($repo, $out);
    }
});

it('refuses an unknown commit', function () {
    $repo = candidateRepo();
    $out = $repo.'-out';
    try {
        $process = candidateBuild($repo, 'deadbeefdeadbeef', $out);

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain("unknown commit 'deadbeefdeadbeef'")
            ->and(is_dir($out))->toBeFalse();
    } finally {
        candidateCleanup($repo, $out);
    }
});

it('refuses a version composer cannot read', function () {
    $repo = candidateRepo();
    $out = $repo.'-out';
    try {
        $script = dirname(__DIR__, 2).'/bin/molly-candidate';
        $process = new Process(['bash', $script, 'HEAD', 'v0.2', '--out', $out, '--repo', $repo], null, ['PHP_BINARY' => PHP_BINARY]);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain("version 'v0.2'");
    } finally {
        candidateCleanup($repo, $out);
    }
});
