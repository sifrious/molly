<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Acceptance\BloomHostInspector;
use Sifrious\Molly\Acceptance\HostObservationRecorder;
use Sifrious\Molly\Acceptance\PackageIdentity;
use Sifrious\Molly\Acceptance\PackageIdentityReader;
use Sifrious\Molly\Acceptance\PermissionState;
use Sifrious\Molly\Acceptance\VerificationMode;
use Sifrious\Molly\Acceptance\VerifierPermissionInspector;
use Sifrious\Molly\Acceptance\VerifierPermissionSnapshot;
use Sifrious\Molly\Acceptance\VerifierProcessIdentity;
use Sifrious\Molly\Actions\ListMollyProjects;
use Sifrious\Molly\Actions\ListTasks;
use Sifrious\Molly\Actions\VerifyMolly;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Projects\MollyProject;
use Sifrious\Molly\Projects\ProjectRegistry;
use Sifrious\Molly\Tests\Support\FixedPermissionInspector;

function observationCandidate(): string
{
    return str_repeat('ab', 20);
}

function bindObservationCandidate(?string $commit): void
{
    app()->instance(PackageIdentityReader::class, new PackageIdentityReader(new PackageIdentity(
        $commit,
        'test',
        '0.2.0',
        null,
        null,
        false,
        null,
        null,
        null,
    )));
}

function bindObservationHost(int $pid, string $plugins, string $load, ?string $command = null): void
{
    $command ??= '/Applications/Bloom.app/Contents/MacOS/Bloom';
    $listing = $pid.' '.$command."\n";
    app()->instance(BloomHostInspector::class, new BloomHostInspector($listing, $plugins, null, $load));
}

function installObservationBundle(string $plugins, bool $binary = true, bool $provider = true): void
{
    $mac = $plugins.'/sifrious.molly/Surfaces.bundle/Contents/MacOS';
    if (! is_dir($mac)) {
        mkdir($mac, 0700, true);
    }
    if ($binary) {
        file_put_contents($mac.'/MollySurfaces', 'bundle');
    }
    $body = $provider ? "<?xml version=\"1.0\"?><plist><key>BloomPluginSurfaceProvider</key><string>MollySurfaceProvider</string></plist>\n" : "<?xml version=\"1.0\"?><plist><string>OtherProvider</string></plist>\n";
    file_put_contents($plugins.'/sifrious.molly/Surfaces.bundle/Contents/Info.plist', $body);
}

function observationDirectory(): string
{
    $directory = sys_get_temp_dir().'/molly-observe-'.bin2hex(random_bytes(4));
    mkdir($directory, 0700, true);
    $directories = test()->verificationDirectories;
    $directories[] = $directory;
    test()->verificationDirectories = $directories;

    return $directory;
}

/**
 * @return array{home: string, project: string, existing: string}
 */
function observationFixture(): array
{
    $home = observationDirectory();
    $root = observationDirectory();
    $project = $root.'/new-app';
    $existing = $root.'/existing-app';
    mkdir($project.'/.molly', 0700, true);
    mkdir($existing.'/.molly', 0700, true);
    $project = realpath($project);
    $existing = realpath($existing);
    putenv('MOLLY_HOME='.$home);
    $_ENV['MOLLY_HOME'] = $home;
    $_SERVER['MOLLY_HOME'] = $home;
    $registry = new ProjectRegistry($home);
    $registry->writeProject(new MollyProject((string) Str::uuid(), 'New', $project, 'new', '2026-10-14T00:00:00Z'));
    $registry->writeProject(new MollyProject((string) Str::uuid(), 'Existing', $existing, 'existing', '2026-10-14T00:00:00Z'));
    Task::create([
        'nickname' => 'bloom-check',
        'prompt' => 'Add a health endpoint.',
        'workspace' => $project,
        'paths' => ['routes/web.php'],
        'test_path' => 'tests/Feature/HealthTest.php',
        'status' => 'running',
    ]);

    return ['home' => $home, 'project' => $project, 'existing' => $existing];
}

function observationPluginDirectory(int $apiVersion = 1): string
{
    $directory = observationDirectory().'/Bloom/Plugins';
    mkdir($directory.'/sifrious.molly', 0700, true);
    file_put_contents($directory.'/sifrious.molly/plugin.json', json_encode([
        'id' => 'sifrious.molly',
        'apiVersion' => $apiVersion,
    ], JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/enabled.json', json_encode(['sifrious.molly'], JSON_THROW_ON_ERROR));

    return $directory;
}

function observationLoad(int $pid = 4242): string
{
    return $pid." /Applications/Bloom Dev.app/Contents/MacOS/MollySurfaces\n";
}

function bindObservationPermissions(): void
{
    app()->instance(VerifierPermissionInspector::class, new FixedPermissionInspector(new VerifierPermissionSnapshot(
        PermissionState::Granted,
        PermissionState::Granted,
        VerifierProcessIdentity::capture(),
        'Darwin',
        '15.6',
        'verification-test',
        new DateTimeImmutable('now', new DateTimeZone('UTC')),
    )));
}

function observationOutcome(array $document, string $id): string
{
    return observationRow($document, $id)['outcome'];
}

function observationReason(array $document, string $id): string
{
    return observationRow($document, $id)['reason_code'];
}

/** @return array<string, mixed> */
function observationRow(array $document, string $id): array
{
    foreach ($document['checks'] as $check) {
        if ($check['check_id'] === $id) {
            return $check;
        }
    }

    throw new RuntimeException('Missing '.$id);
}

/**
 * @return array<string, mixed>
 */
function observeVerify(string $project, VerificationMode $mode): array
{
    return app(VerifyMolly::class)->verify($mode, observationDirectory(), observationCandidate(), null, $project);
}

beforeEach(function () {
    $this->verificationDirectories = [];
    $this->mollyHomeBefore = getenv('MOLLY_HOME');
    $this->hostReaderBefore = getenv('MOLLY_HOST_READER');
});

afterEach(function () {
    $previous = $this->mollyHomeBefore;
    if (! is_string($previous) || $previous === '') {
        putenv('MOLLY_HOME');
        unset($_ENV['MOLLY_HOME'], $_SERVER['MOLLY_HOME']);
    } else {
        putenv('MOLLY_HOME='.$previous);
        $_ENV['MOLLY_HOME'] = $previous;
        $_SERVER['MOLLY_HOME'] = $previous;
    }
    $reader = $this->hostReaderBefore;
    if (! is_string($reader) || $reader === '') {
        putenv('MOLLY_HOST_READER');
        unset($_ENV['MOLLY_HOST_READER'], $_SERVER['MOLLY_HOST_READER']);
    } else {
        putenv('MOLLY_HOST_READER='.$reader);
    }
    foreach ($this->verificationDirectories ?? [] as $directory) {
        File::deleteDirectory($directory);
    }
});

it('writes a new-project observation and lets the verifier pass only from that record', function () {
    $fixture = observationFixture();
    $sha = observationCandidate();
    $plugins = observationPluginDirectory();
    installObservationBundle($plugins);
    bindObservationCandidate($sha);
    bindObservationHost(4242, $plugins, observationLoad());
    bindObservationPermissions();

    $missing = observeVerify($fixture['project'], VerificationMode::Default);
    expect(observationOutcome($missing, 'M04.2'))->toBe('BLOCKED_PREREQUISITE')
        ->and(observationReason($missing, 'M04.2'))->toBe('COMPILED_HOST_NOT_OBSERVED');

    $directory = $fixture['project'].'/acceptance-native';
    mkdir($directory, 0700, true);
    file_put_contents($directory.'/M04.2.json', json_encode([
        'candidate_sha' => $sha,
        'assertion' => 'claimed',
        'expected' => 'source new',
        'actual' => 'source new',
        'source_artifact' => 'M04.2-observed.json',
    ], JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/M04.2-observed.json', '{'."\n");
    $handWritten = observeVerify($fixture['project'], VerificationMode::Default);
    expect(observationReason($handWritten, 'M04.2'))->toBe('COMPILED_HOST_NOT_OBSERVED')
        ->and(observationOutcome($handWritten, 'M04.2'))->not->toBe('PASS');

    $result = app(HostObservationRecorder::class)->recordProject($fixture['project'], 'plugin');
    $record = json_decode((string) file_get_contents($fixture['project'].'/acceptance-native/M04.2.json'), true);
    $artifact = json_decode((string) file_get_contents($fixture['project'].'/acceptance-native/M04.2-observed.json'), true);

    expect($result['records'][0]['check'])->toBe('M04.2')
        ->and($record['candidate_sha'])->toBe($sha)
        ->and($record['assertion'])->toBe('M04.2 lists a project whose source is new.')
        ->and($record['expected'])->toBe('source new')
        ->and($record['actual'])->toBe('source new')
        ->and($record['source_artifact'])->toBe('M04.2-observed.json')
        ->and($artifact['candidate_sha'])->toBe($sha)
        ->and($artifact['actual'])->toBe('source new')
        ->and($artifact['observed']['project']['source'])->toBe('new')
        ->and($artifact['observed']['project']['path'])->toBe($fixture['project']);

    $passed = observeVerify($fixture['project'], VerificationMode::Default);
    $permissionless = observeVerify($fixture['project'], VerificationMode::Permissionless);

    expect(observationOutcome($passed, 'M04.2'))->toBe('PASS')
        ->and(observationReason($passed, 'M04.2'))->toBe('NATIVE_OBSERVATION_MATCHED')
        ->and(observationReason($permissionless, 'M04.2'))->toBe('NATIVE_OBSERVATION_DISABLED')
        ->and(observationOutcome($permissionless, 'M04.2'))->not->toBe('PASS');
});

it('reads the new project observation when verify is pointed at another app', function () {
    $fixture = observationFixture();
    $sha = observationCandidate();
    $plugins = observationPluginDirectory();
    installObservationBundle($plugins);
    bindObservationCandidate($sha);
    bindObservationHost(4242, $plugins, observationLoad());
    bindObservationPermissions();
    $native = $fixture['project'].'/acceptance-native';
    mkdir($native, 0700, true);
    file_put_contents($native.'/M04.2.json', json_encode([
        'candidate_sha' => $sha,
        'assertion' => 'claimed',
        'expected' => 'source new',
        'actual' => 'source new',
        'source_artifact' => 'M04.2-observed.json',
    ], JSON_THROW_ON_ERROR));
    file_put_contents($native.'/M04.2-observed.json', '{'."\n");

    $handWritten = observeVerify($fixture['existing'], VerificationMode::Default);
    expect(observationReason($handWritten, 'M04.2'))->toBe('COMPILED_HOST_NOT_OBSERVED')
        ->and(observationOutcome($handWritten, 'M04.2'))->not->toBe('PASS');

    app(HostObservationRecorder::class)->recordProject($fixture['project'], 'plugin');
    $fromExisting = observeVerify($fixture['existing'], VerificationMode::Default);
    $matched = observationRow($fromExisting, 'M04.2');

    $source = null;
    foreach ($matched['assertions'] as $assertion) {
        if (is_string($assertion['source_artifact'] ?? null)) {
            $source = $assertion['source_artifact'];
        }
    }

    expect(observationOutcome($fromExisting, 'M04.2'))->toBe('PASS')
        ->and(observationReason($fromExisting, 'M04.2'))->toBe('NATIVE_OBSERVATION_MATCHED')
        ->and($source)->toBe($fixture['project'].'/acceptance-native/M04.2-observed.json');

    File::deleteDirectory($native);
    $onExisting = $fixture['existing'].'/acceptance-native';
    mkdir($onExisting, 0700, true);
    file_put_contents($onExisting.'/M04.2.json', json_encode([
        'candidate_sha' => $sha,
        'assertion' => 'M04.2 lists a project whose source is new.',
        'expected' => 'source new',
        'actual' => 'source new',
        'source_artifact' => 'M04.2-observed.json',
    ], JSON_THROW_ON_ERROR));
    file_put_contents($onExisting.'/M04.2-observed.json', json_encode([
        'candidate_sha' => $sha,
        'actual' => 'source new',
    ], JSON_THROW_ON_ERROR));
    $other = observationDirectory().'/other-app';
    mkdir($other, 0700, true);
    $ignored = observeVerify($other, VerificationMode::Default);

    expect(observationReason($ignored, 'M04.2'))->toBe('COMPILED_HOST_NOT_OBSERVED')
        ->and(observationOutcome($ignored, 'M04.2'))->not->toBe('PASS');
});

it('writes an existing-project observation from the listed source', function () {
    $fixture = observationFixture();
    $sha = observationCandidate();
    $plugins = observationPluginDirectory();
    installObservationBundle($plugins);
    bindObservationCandidate($sha);
    bindObservationHost(4242, $plugins, observationLoad());
    bindObservationPermissions();
    $existing = $fixture['existing'];

    $result = app(HostObservationRecorder::class)->recordProject($existing, 'plugin');
    $record = json_decode((string) file_get_contents($existing.'/acceptance-native/M04.3.json'), true);
    $passed = observeVerify($existing, VerificationMode::Default);

    expect($result['records'][0]['check'])->toBe('M04.3')
        ->and($record['expected'])->toBe('source existing')
        ->and($record['actual'])->toBe('source existing')
        ->and(is_file($existing.'/acceptance-native/M04.2.json'))->toBeFalse()
        ->and(observationOutcome($passed, 'M04.3'))->toBe('PASS')
        ->and(observationReason($passed, 'M04.3'))->toBe('NATIVE_OBSERVATION_MATCHED');
});

it('does not record a project the registry does not list or a reader that is not the plugin', function () {
    $fixture = observationFixture();
    bindObservationCandidate(observationCandidate());
    $plugins = observationPluginDirectory();
    installObservationBundle($plugins);
    bindObservationHost(4242, $plugins, observationLoad());
    $unlisted = observationDirectory().'/unlisted';
    mkdir($unlisted, 0700, true);

    $cli = app(HostObservationRecorder::class)->recordProject($fixture['project'], 'cli');
    $absent = app(HostObservationRecorder::class)->recordProject($unlisted, 'plugin');
    app()->instance(BloomHostInspector::class, new BloomHostInspector("9 /usr/bin/php /tmp/not-bloom\n", $plugins, null, ''));
    $noHost = app(HostObservationRecorder::class)->recordProject($fixture['project'], 'plugin');
    bindObservationCandidate(null);
    bindObservationHost(4242, $plugins, observationLoad());
    $unverified = app(HostObservationRecorder::class)->recordProject($fixture['project'], 'plugin');

    expect($cli['records'])->toBe([])
        ->and($cli['skipped'][0]['reason'])->toBe('READER_NOT_PLUGIN')
        ->and($absent['skipped'][0]['reason'])->toBe('PROJECT_NOT_LISTED')
        ->and($noHost['skipped'][0]['reason'])->toBe('HOST_NOT_OBSERVED')
        ->and($unverified['skipped'][0]['reason'])->toBe('CANDIDATE_UNVERIFIED')
        ->and(is_file($fixture['project'].'/acceptance-native/M04.2.json'))->toBeFalse();
});

it('keeps M04.2 unobserved when no project source is new', function () {
    $fixture = observationFixture();
    $sha = observationCandidate();
    $plugins = observationPluginDirectory();
    installObservationBundle($plugins);
    bindObservationCandidate($sha);
    bindObservationHost(4242, $plugins, observationLoad());
    bindObservationPermissions();
    $existing = null;
    $paths = json_decode((string) file_get_contents($fixture['home'].'/projects.json'), true);
    $kept = [];
    foreach ($paths as $path) {
        $meta = json_decode((string) file_get_contents($path.'/.molly/project.json'), true);
        if ($meta['source'] === 'existing') {
            $existing = $path;
            $kept[] = $path;
        }
    }
    file_put_contents($fixture['home'].'/projects.json', json_encode($kept, JSON_THROW_ON_ERROR));

    $result = app(HostObservationRecorder::class)->recordProject($existing, 'plugin');
    $document = observeVerify($existing, VerificationMode::Default);

    expect($result['records'][0]['check'])->toBe('M04.3')
        ->and(observationReason($document, 'M04.2'))->toBe('PROJECT_NOT_OBSERVED')
        ->and(observationOutcome($document, 'M04.2'))->not->toBe('PASS')
        ->and(observationOutcome($document, 'M04.3'))->toBe('PASS');
});

it('writes the task the plugin listed and passes M04.12 only from that record', function () {
    $fixture = observationFixture();
    $sha = observationCandidate();
    $plugins = observationPluginDirectory();
    installObservationBundle($plugins);
    bindObservationCandidate($sha);
    bindObservationHost(4242, $plugins, observationLoad());
    bindObservationPermissions();
    $taskId = (string) Task::query()->where('workspace', $fixture['project'])->value('id');

    $before = observeVerify($fixture['project'], VerificationMode::Default);
    $cli = app(HostObservationRecorder::class)->recordTask($fixture['project'], $taskId, 'cli');
    $missing = app(HostObservationRecorder::class)->recordTask($fixture['project'], 'not-a-task', 'plugin');
    $result = app(HostObservationRecorder::class)->recordTask($fixture['project'], $taskId, 'plugin');
    $record = json_decode((string) file_get_contents($fixture['project'].'/acceptance-native/M04.12.json'), true);
    $artifact = json_decode((string) file_get_contents($fixture['project'].'/acceptance-native/M04.12-observed.json'), true);
    $passed = observeVerify($fixture['project'], VerificationMode::Default);

    expect(observationReason($before, 'M04.12'))->toBe('COMPILED_HOST_NOT_OBSERVED')
        ->and($cli['skipped'][0]['reason'])->toBe('READER_NOT_PLUGIN')
        ->and($missing['skipped'][0]['reason'])->toBe('TASK_NOT_LISTED')
        ->and($record['expected'])->toBe($taskId)
        ->and($record['actual'])->toBe($taskId)
        ->and($artifact['actual'])->toBe($taskId)
        ->and($artifact['observed']['task_id'])->toBe($taskId)
        ->and(observationOutcome($passed, 'M04.12'))->toBe('PASS')
        ->and(observationReason($passed, 'M04.12'))->toBe('NATIVE_OBSERVATION_MATCHED')
        ->and(observationReason($passed, 'M04.16'))->toBe('RESTART_PERSISTENCE_NOT_OBSERVED');
});

it('writes an incompat log only when the running host did not load another apiVersion', function () {
    $fixture = observationFixture();
    $sha = observationCandidate();
    $plugins = observationPluginDirectory(2);
    installObservationBundle($plugins);
    bindObservationCandidate($sha);
    bindObservationHost(4242, $plugins, observationLoad());
    bindObservationPermissions();

    $loaded = app(HostObservationRecorder::class)->recordDiagnostics($fixture['project']);
    expect($loaded['skipped'][0]['reason'])->toBe('INCOMPAT_NOT_OBSERVED')
        ->and(is_file($fixture['project'].'/host-incompat.log'))->toBeFalse();

    bindObservationHost(4242, $plugins, '');
    $result = app(HostObservationRecorder::class)->recordDiagnostics($fixture['project']);
    $log = json_decode((string) file_get_contents($fixture['project'].'/host-incompat.log'), true);
    $passed = observeVerify($fixture['project'], VerificationMode::Permissionless);

    expect($result['records'][0]['check'])->toBe('M04.13')
        ->and($log['process']['pid'])->toBe(4242)
        ->and($log['host'])->toBe('/Applications/Bloom.app/Contents/MacOS/Bloom')
        ->and($log['plugin'])->toBe('sifrious.molly')
        ->and($log['candidate'])->toBe($sha)
        ->and($log['observed_at'])->not->toBe('')
        ->and($log['loaded'])->toBeFalse()
        ->and($log['api_version'])->toBe(2)
        ->and(observationOutcome($passed, 'M04.13'))->toBe('PASS')
        ->and(observationReason($passed, 'M04.13'))->toBe('INCOMPAT_LOG_RECORDED');
});

it('writes a broken-asset log from the plugin files that are actually missing', function () {
    $fixture = observationFixture();
    $sha = observationCandidate();
    $plugins = observationPluginDirectory();
    installObservationBundle($plugins, binary: false, provider: true);
    bindObservationCandidate($sha);
    bindObservationHost(4242, $plugins, observationLoad());
    bindObservationPermissions();

    $healthy = observationPluginDirectory();
    installObservationBundle($healthy);
    bindObservationHost(4242, $healthy, observationLoad());
    $present = app(HostObservationRecorder::class)->recordDiagnostics($fixture['project']);
    expect($present['skipped'][1]['reason'])->toBe('ASSETS_PRESENT')
        ->and(is_file($fixture['project'].'/host-broken.log'))->toBeFalse();

    bindObservationHost(4242, $plugins, observationLoad());
    $result = app(HostObservationRecorder::class)->recordDiagnostics($fixture['project']);
    $log = json_decode((string) file_get_contents($fixture['project'].'/host-broken.log'), true);
    $passed = observeVerify($fixture['project'], VerificationMode::Permissionless);

    expect($result['records'][0]['check'])->toBe('M04.15')
        ->and($log['process']['pid'])->toBe(4242)
        ->and($log['host'])->toBe('/Applications/Bloom.app/Contents/MacOS/Bloom')
        ->and($log['plugin'])->toBe('sifrious.molly')
        ->and($log['candidate'])->toBe($sha)
        ->and($log['observed_at'])->not->toBe('')
        ->and($log['missing_bundle'])->toBeTrue()
        ->and($log['missing_provider'])->toBeFalse()
        ->and(observationOutcome($passed, 'M04.15'))->toBe('PASS')
        ->and(observationReason($passed, 'M04.15'))->toBe('BROKEN_ASSETS_RECORDED')
        ->and(observationReason($passed, 'M04.13'))->toBe('INCOMPAT_LOG_NOT_OBSERVED');
});

it('accepts a broken-host record when that Bloom pid has exited and the same app loaded the plugin again', function () {
    $fixture = observationFixture();
    $sha = observationCandidate();
    $plugins = observationPluginDirectory();
    installObservationBundle($plugins);
    bindObservationCandidate($sha);
    bindObservationHost(4242, $plugins, observationLoad());
    bindObservationPermissions();
    $command = '/Applications/Bloom.app/Contents/MacOS/Bloom';
    $log = [
        'process' => ['pid' => 1111],
        'host' => $command,
        'plugin' => 'sifrious.molly',
        'candidate' => $sha,
        'observed_at' => '2026-10-08T02:00:00.000000000Z',
        'missing_bundle' => true,
        'missing_provider' => false,
    ];
    file_put_contents($fixture['project'].'/host-broken.log', json_encode($log, JSON_THROW_ON_ERROR));

    $passed = observeVerify($fixture['project'], VerificationMode::Permissionless);
    expect(observationOutcome($passed, 'M04.15'))->toBe('PASS')
        ->and(observationReason($passed, 'M04.15'))->toBe('BROKEN_ASSETS_RECORDED');

    $incompat = $log;
    unset($incompat['missing_bundle'], $incompat['missing_provider']);
    $incompat['loaded'] = false;
    $incompat['api_version'] = 2;
    file_put_contents($fixture['project'].'/host-incompat.log', json_encode($incompat, JSON_THROW_ON_ERROR));
    $incompatPassed = observeVerify($fixture['project'], VerificationMode::Permissionless);
    expect(observationOutcome($incompatPassed, 'M04.13'))->toBe('PASS')
        ->and(observationReason($incompatPassed, 'M04.13'))->toBe('INCOMPAT_LOG_RECORDED');

    $log['observed_at'] = '2099-01-01T00:00:00Z';
    file_put_contents($fixture['project'].'/host-broken.log', json_encode($log, JSON_THROW_ON_ERROR));
    $future = observeVerify($fixture['project'], VerificationMode::Permissionless);
    expect(observationReason($future, 'M04.15'))->toBe('HOST_DIAGNOSTIC_NOT_OBSERVED');

    $log['observed_at'] = '2026-10-08T02:00:00Z';
    $log['host'] = '/Applications/Other.app/Contents/MacOS/Other';
    file_put_contents($fixture['project'].'/host-broken.log', json_encode($log, JSON_THROW_ON_ERROR));
    $otherApp = observeVerify($fixture['project'], VerificationMode::Permissionless);
    expect(observationReason($otherApp, 'M04.15'))->toBe('HOST_DIAGNOSTIC_NOT_OBSERVED');

    $log['host'] = $command;
    file_put_contents($fixture['project'].'/host-broken.log', json_encode($log, JSON_THROW_ON_ERROR));
    $listing = "1111 /usr/bin/sleep\n4242 ".$command."\n";
    app()->instance(BloomHostInspector::class, new BloomHostInspector($listing, $plugins, null, observationLoad()));
    $alive = observeVerify($fixture['project'], VerificationMode::Permissionless);
    expect(observationReason($alive, 'M04.15'))->toBe('HOST_DIAGNOSTIC_NOT_OBSERVED');

    bindObservationHost(4242, $plugins, '');
    $unloaded = observeVerify($fixture['project'], VerificationMode::Permissionless);
    expect(observationReason($unloaded, 'M04.15'))->toBe('HOST_DIAGNOSTIC_NOT_OBSERVED')
        ->and(observationOutcome($unloaded, 'M04.15'))->not->toBe('PASS');
});

it('accepts the Studio broken-host record after Bloom Dev relaunches', function () {
    $fixture = observationFixture();
    $sha = observationCandidate();
    $plugins = observationPluginDirectory();
    installObservationBundle($plugins);
    bindObservationCandidate($sha);
    $command = '/Applications/Bloom Dev.app/Contents/MacOS/Bloom Dev';
    bindObservationHost(49023, $plugins, observationLoad(49023), $command);
    bindObservationPermissions();
    file_put_contents($fixture['project'].'/host-broken.log', json_encode([
        'process' => ['pid' => 35657],
        'host' => $command,
        'plugin' => 'sifrious.molly',
        'candidate' => $sha,
        'observed_at' => '2026-10-14T03:32:43.123456789Z',
        'missing_bundle' => true,
        'missing_provider' => false,
    ], JSON_THROW_ON_ERROR));

    $passed = observeVerify($fixture['project'], VerificationMode::Permissionless);
    expect(observationOutcome($passed, 'M04.15'))->toBe('PASS')
        ->and(observationReason($passed, 'M04.15'))->toBe('BROKEN_ASSETS_RECORDED');

    file_put_contents($fixture['project'].'/host-broken.log', json_encode([
        'process' => ['pid' => 35657],
        'host' => '/Users/mme/other/Bloom Dev.app/Contents/MacOS/Bloom Dev',
        'plugin' => 'sifrious.molly',
        'candidate' => $sha,
        'observed_at' => '2026-10-14T03:32:43.123456789Z',
        'missing_bundle' => true,
        'missing_provider' => false,
    ], JSON_THROW_ON_ERROR));
    $otherCopy = observeVerify($fixture['project'], VerificationMode::Permissionless);
    expect(observationReason($otherCopy, 'M04.15'))->toBe('HOST_DIAGNOSTIC_NOT_OBSERVED');
});

it('passes M04.16 only after one plugin read and one CLI read on different pids', function () {
    $fixture = observationFixture();
    $sha = observationCandidate();
    $plugins = observationPluginDirectory();
    installObservationBundle($plugins);
    bindObservationCandidate($sha);
    bindObservationPermissions();
    $taskId = (string) Task::query()->where('workspace', $fixture['project'])->value('id');
    $projects = app(ListMollyProjects::class);
    $tasks = app(ListTasks::class);
    $packages = app(PackageIdentityReader::class);
    $pluginHost = new BloomHostInspector("1111 /Applications/Bloom.app/Contents/MacOS/Bloom\n", $plugins, null, observationLoad(1111));
    $cliHost = new BloomHostInspector("5151 /Applications/Bloom.app/Contents/MacOS/Bloom\n", $plugins, null, observationLoad(5151));

    $pluginOnly = (new HostObservationRecorder($projects, $tasks, $pluginHost, $packages))->recordRestart($fixture['project'], $taskId, 'plugin');
    bindObservationHost(4242, $plugins, observationLoad());
    $oneSided = observeVerify($fixture['project'], VerificationMode::Permissionless);

    $samePid = (new HostObservationRecorder($projects, $tasks, $pluginHost, $packages))->recordRestart($fixture['project'], $taskId, 'cli');
    bindObservationHost(1111, $plugins, observationLoad(1111));
    $same = observeVerify($fixture['project'], VerificationMode::Permissionless);

    $cli = (new HostObservationRecorder($projects, $tasks, $cliHost, $packages))->recordRestart($fixture['project'], $taskId, 'cli');
    bindObservationHost(5151, $plugins, observationLoad(5151));
    $passed = observeVerify($fixture['project'], VerificationMode::Permissionless);
    $state = json_decode((string) file_get_contents($fixture['project'].'/.molly/restart-state.json'), true);

    expect($pluginOnly['records'][0]['check'])->toBe('M04.16')
        ->and(observationReason($oneSided, 'M04.16'))->toBe('RESTART_PERSISTENCE_NOT_OBSERVED')
        ->and(observationReason($same, 'M04.16'))->toBe('RESTART_PERSISTENCE_NOT_OBSERVED')
        ->and($state['process']['pid'])->toBe(5151)
        ->and($state['plugin'])->toBe('sifrious.molly')
        ->and($state['candidate'])->toBe($sha)
        ->and($state['before']['source'])->toBe('plugin')
        ->and($state['before']['pid'])->toBe(1111)
        ->and($state['before']['task_id'])->toBe($taskId)
        ->and($state['after']['source'])->toBe('cli')
        ->and($state['after']['pid'])->toBe(5151)
        ->and($state['after']['task_id'])->toBe($taskId)
        ->and($state['before']['observed_at'])->not->toBe($state['after']['observed_at'])
        ->and(observationOutcome($passed, 'M04.16'))->toBe('PASS')
        ->and(observationReason($passed, 'M04.16'))->toBe('RESTART_STATE_KEPT')
        ->and($samePid['records'][0]['check'])->toBe('M04.16')
        ->and($cli['records'][0]['path'])->toBe($fixture['project'].'/.molly/restart-state.json');
});

it('records a plugin project observation from the command and ignores a CLI reader', function () {
    $fixture = observationFixture();
    $plugins = observationPluginDirectory();
    installObservationBundle($plugins);
    bindObservationCandidate(observationCandidate());
    bindObservationHost(4242, $plugins, observationLoad());

    $this->artisan('molly:host-observe', [
        'kind' => 'project',
        '--project' => $fixture['project'],
        '--json' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();
    expect(is_file($fixture['project'].'/acceptance-native/M04.2.json'))->toBeFalse();

    putenv('MOLLY_HOST_READER=plugin');
    $_ENV['MOLLY_HOST_READER'] = 'plugin';
    $this->artisan('molly:host-observe', [
        'kind' => 'project',
        '--project' => $fixture['project'],
        '--json' => true,
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(is_file($fixture['project'].'/acceptance-native/M04.2.json'))->toBeTrue()
        ->and(is_file($fixture['project'].'/acceptance-native/M04.2-observed.json'))->toBeTrue();
});
