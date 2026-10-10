<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('refuses a non-empty target directory without --force', function () {
    $script = dirname(__DIR__, 2).'/bin/molly-demo';
    expect(is_file($script))->toBeTrue();

    $dir = sys_get_temp_dir().'/molly-demo-download-'.Str::uuid();
    File::ensureDirectoryExists($dir);
    File::put($dir.'/marker.txt', 'keep');

    // The script checks for a Git identity before the target directory. tests/Pest.php gives
    // every process a test identity, so the directory check is the one that refuses.
    $process = new Process(['bash', $script, $dir]);
    $process->setTimeout(30);
    $process->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput().$process->getOutput())->toContain('Refusing')
        ->and(File::exists($dir.'/marker.txt'))->toBeTrue();

    File::deleteDirectory($dir);
});

it('prints help without creating a project', function () {
    $script = dirname(__DIR__, 2).'/bin/molly-demo';
    $process = new Process(['bash', $script, '--help']);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('Usage: molly-demo');
});

function demoReleaseFixture(string $result, bool $existing = false, string $constraint = '^0.2'): array
{
    $root = sys_get_temp_dir().'/molly-release-test-'.Str::uuid();
    File::ensureDirectoryExists($root.'/bin');
    File::ensureDirectoryExists($root.'/tmp');
    if ($existing) {
        File::ensureDirectoryExists($root.'/app');
        File::put($root.'/app/marker.txt', 'keep');
    }
    File::put($root.'/bin/composer', <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$MOLLY_TEST_LOG"
if [[ "$*" == *--dry-run* ]]; then
  [[ "$*" == *'sifrious/molly:^0.2@stable'* && "$*" == *--no-scripts* && "$*" == *--no-plugins* && "$*" == *--no-cache* ]] || exit 91
  case "$MOLLY_TEST_RESULT" in
    missing) echo 'No matching version: only v0.1.0 through v0.1.3' >&2; exit 2 ;;
    offline) echo 'Repository connection failed' >&2; exit 1 ;;
    fallback) grep -q 'github.com/sifrious/molly' "${1#--working-dir=}/composer.json" || exit 2 ;;
  esac
  echo 'Would install sifrious/molly (0.2.0)'
  exit 0
fi
if [[ "$1" == create-project ]]; then
  mkdir -p "$3"
  printf '%s\n' '{"name":"example/app","require":{}}' > "$3/composer.json"
fi
if [[ "$MOLLY_TEST_RESULT" == late-failure && "$*" == *'sifrious/molly:'* ]]; then
  echo 'Artifact download failed' >&2
  exit 1
fi
BASH);
    File::put($root.'/bin/php', "#!/usr/bin/env bash\nprintf 'php %s\\n' \"\$*\" >> \"\$MOLLY_TEST_LOG\"\n");
    File::put($root.'/bin/curl', "#!/usr/bin/env bash\nprintf 200\n");
    chmod($root.'/bin/curl', 0755);
    chmod($root.'/bin/composer', 0755);
    chmod($root.'/bin/php', 0755);
    $process = new Process(['bash', dirname(__DIR__, 2).'/bin/molly-demo', $root.'/app', ...($existing ? ['--force'] : [])], env: [
        'PATH' => $root.'/bin:'.getenv('PATH'),
        'TMPDIR' => $root.'/tmp',
        'MOLLY_TEST_LOG' => $root.'/commands.log',
        'MOLLY_TEST_RESULT' => $result,
        'MOLLY_CONSTRAINT' => $constraint,
    ]);
    $process->setTimeout(30);
    $process->run();

    return [$root, $process, File::exists($root.'/commands.log') ? File::get($root.'/commands.log') : ''];
}

it('leaves the demo target untouched when release resolution fails', function (string $reason, bool $existing): void {
    [$root, $process, $commands] = demoReleaseFixture($reason, $existing);
    try {
        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('MOLLY_RELEASE_UNAVAILABLE', '^0.2', 'Target left unchanged', 'docs/acceptance/README.md')
            ->and($commands)->not->toContain('create-project', 'pestphp/pest', 'artisan')
            ->and(File::exists($root.'/app'))->toBe($existing)
            ->and(File::glob($root.'/tmp/molly-release.*'))->toBe([]);
        if ($existing) {
            expect(File::get($root.'/app/marker.txt'))->toBe('keep');
        }
    } finally {
        File::deleteDirectory($root);
    }
})->with([
    'missing tags, new target' => ['missing', false],
    'missing tags, force target' => ['missing', true],
    'repositories unavailable, new target' => ['offline', false],
    'repositories unavailable, force target' => ['offline', true],
]);

it('checks a valid release before creating the app and uses the checked repository', function (string $source): void {
    [$root, $process, $commands] = demoReleaseFixture($source);
    try {
        expect($process->getExitCode())->toBe(0)
            ->and(strpos($commands, '--dry-run'))->toBeLessThan(strpos($commands, 'create-project'))
            ->and($commands)->toContain('require --dev sifrious/molly:^0.2@stable --no-interaction')
            ->and($process->getOutput())->toContain('Molly is ready')
            ->and(File::glob($root.'/tmp/molly-release.*'))->toBe([]);
        expect(str_contains($commands, 'config repositories.molly vcs'))->toBe($source === 'fallback');
    } finally {
        File::deleteDirectory($root);
    }
})->with(['available', 'fallback']);

it('does not report readiness if installation fails after a successful preflight', function (): void {
    [$root, $process] = demoReleaseFixture('late-failure');
    try {
        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('MOLLY_INSTALL_FAILED', 'incomplete')
            ->and($process->getOutput())->not->toContain('Molly is ready');
    } finally {
        File::deleteDirectory($root);
    }
});

it('directs unpublished requests to artifact acceptance without creating an app', function (string $constraint): void {
    [$root, $process, $commands] = demoReleaseFixture('available', constraint: $constraint);
    try {
        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('MOLLY_RELEASE_REQUIRED', 'checksummed artifact')
            ->and($commands)->toBe('')
            ->and(File::exists($root.'/app'))->toBeFalse();
    } finally {
        File::deleteDirectory($root);
    }
})->with(['dev-main', '0.2.0-RC11', '^0.2@dev']);
