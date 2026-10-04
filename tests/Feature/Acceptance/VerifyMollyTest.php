<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Sifrious\Molly\Acceptance\BloomHostInspector;
use Sifrious\Molly\Acceptance\CheckOutcome;
use Sifrious\Molly\Acceptance\CompiledHostBloomObserver;
use Sifrious\Molly\Acceptance\MacOsPermissionPrompt;
use Sifrious\Molly\Acceptance\MacOsTccProbe;
use Sifrious\Molly\Acceptance\MacOsVerifierPermissionInspector;
use Sifrious\Molly\Acceptance\MacOsVerifierPermissionRequester;
use Sifrious\Molly\Acceptance\PackageRoot;
use Sifrious\Molly\Acceptance\PermissionKind;
use Sifrious\Molly\Acceptance\PermissionState;
use Sifrious\Molly\Acceptance\VerificationContext;
use Sifrious\Molly\Acceptance\VerificationMode;
use Sifrious\Molly\Acceptance\VerifierPermissionInspector;
use Sifrious\Molly\Acceptance\VerifierPermissionSnapshot;
use Sifrious\Molly\Acceptance\VerifierProcessIdentity;
use Sifrious\Molly\Actions\VerifyMolly;
use Sifrious\Molly\Conversations\Conversation;
use Sifrious\Molly\Conversations\ConversationStore;
use Sifrious\Molly\Journal\JournalRenderer;
use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphNode;
use Sifrious\Molly\Knowledge\GraphSource;
use Sifrious\Molly\Mcp\MollyServer;
use Sifrious\Molly\Mcp\MollyVerify;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Projects\MollyProject;
use Sifrious\Molly\Projects\ProjectRegistry;
use Sifrious\Molly\Settings\MollySettings;
use Sifrious\Molly\Settings\SettingsStore;
use Sifrious\Molly\Tests\Support\FixedPermissionInspector;
use Sifrious\Molly\Tests\Support\SequencePermissionInspector;
use Sifrious\Molly\Tests\Support\StubPermissionPrompt;

function verificationSha(): string
{
    return str_repeat('ab', 20);
}

beforeEach(function () {
    $this->verificationDirectories = [];
    $this->mollyHomeBefore = getenv('MOLLY_HOME');
    app()->instance(MacOsPermissionPrompt::class, new StubPermissionPrompt(false));
});

function verificationDirectory(): string
{
    $directory = sys_get_temp_dir().'/molly-verify-'.bin2hex(random_bytes(4));
    mkdir($directory, 0700, true);
    $directories = test()->verificationDirectories;
    $directories[] = $directory;
    test()->verificationDirectories = $directories;

    return $directory;
}

function bindPermissions(PermissionState $screen, PermissionState $accessibility, string $platform = 'Darwin'): void
{
    app()->instance(VerifierPermissionInspector::class, new FixedPermissionInspector(permissionSnapshot($screen, $accessibility, $platform)));
}

function permissionSnapshot(PermissionState $screen, PermissionState $accessibility, string $platform = 'Darwin'): VerifierPermissionSnapshot
{
    return new VerifierPermissionSnapshot(
        $screen,
        $accessibility,
        VerifierProcessIdentity::capture(),
        $platform,
        $platform === 'Darwin' ? '15.6' : null,
        'verification-test',
        new DateTimeImmutable('now', new DateTimeZone('UTC')),
    );
}

/**
 * @return array{home: string, project: string, evidence: string}
 */
function bloomFixture(string $effectiveModel = 'local-test-model'): array
{
    $home = verificationDirectory();
    $root = verificationDirectory();
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
    $registry->writeProject(new MollyProject((string) Str::uuid(), 'New', $project, 'new', '2026-10-04T12:00:00Z'));
    $registry->writeProject(new MollyProject((string) Str::uuid(), 'Existing', $existing, 'existing', '2026-10-04T12:00:00Z'));
    (new SettingsStore($home))->write(MollySettings::fromArray(['runtime' => ['model' => 'local-test-model']]));

    $task = Task::create([
        'nickname' => 'bloom-check',
        'prompt' => 'Add a health endpoint.',
        'workspace' => $project,
        'paths' => ['routes/web.php'],
        'test_path' => 'tests/Feature/HealthTest.php',
        'status' => 'running',
        'worker_id' => 'worker-1',
    ]);
    $run = Run::create([
        'task_id' => $task->id,
        'prompt' => $task->prompt,
        'workspace' => $project,
        'status' => 'passed',
        'report' => [
            'verification' => ['passed' => 1, 'failed' => 0],
            'diff' => ['id' => 'diff-1', 'task_id' => $task->id],
            'receipt' => ['id' => 'receipt-1'],
        ],
        'effective_config' => ['config' => ['runtime' => ['model' => $effectiveModel]]],
    ]);
    $run->forceFill([
        'report' => [
            'verification' => ['passed' => 1, 'failed' => 0],
            'diff' => ['id' => 'diff-1', 'task_id' => $task->id, 'run_id' => $run->id],
            'receipt' => ['id' => 'receipt-1', 'run_id' => $run->id],
        ],
    ])->save();
    app(ConversationStore::class)->put(new Conversation(
        (string) Str::uuid(),
        'Health',
        [[
            'id' => 'm1',
            'sequence' => 1,
            'role' => 'user',
            'body' => 'Add a health endpoint.',
            'at' => '2026-10-04T12:00:00Z',
            'provenance' => ['source' => 'test', 'kind' => 'fixture'],
        ]],
        [$task->id],
        [$run->id],
        '2026-10-04T12:00:00Z',
        '2026-10-04T12:00:00Z',
        $project,
    ));

    $renderer = new JournalRenderer;
    file_put_contents($project.'/.molly/GLOSSARY.md', $renderer->glossaryCopy($renderer->glossarySourceLink($project)));
    file_put_contents($project.'/.molly/restart-state.json', json_encode([
        'before' => ['task_id' => $task->id],
        'after' => ['task_id' => $task->id],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($project.'/host-incompat.log', "plugin sifrious.molly skipped: apiVersion 2 is not supported. host stable\n");
    file_put_contents($project.'/host-broken.log', "status missingBundle missingProvider\n");

    return ['home' => $home, 'project' => $project, 'evidence' => verificationDirectory()];
}

function bloomPluginDirectory(string $id = 'sifrious.molly', int $apiVersion = 1, bool $enabled = true): string
{
    $directory = verificationDirectory().'/Bloom/Plugins';
    mkdir($directory.'/sifrious.molly', 0700, true);
    file_put_contents($directory.'/sifrious.molly/plugin.json', json_encode([
        'id' => $id,
        'apiVersion' => $apiVersion,
    ], JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/enabled.json', json_encode($enabled ? [$id] : [], JSON_THROW_ON_ERROR));

    return $directory;
}

function bloomProcessListing(int $pid = 4242): string
{
    return $pid." /Applications/Bloom.app/Contents/MacOS/Bloom\n";
}

function seedTaskGraph(string $project): string
{
    $database = $project.'/.molly/knowledge.sqlite';
    config()->set('molly.knowledge.database', $database);
    $version = substr(hash('sha256', $project), 0, 12);
    $source = new GraphSource('project', $version, 'task', 'task-1', 'Saved task', $project.'/.molly/tasks');
    $node = new GraphNode('project', $version, 'task', 'Task', 'Task', [$source->id()]);
    app(Graph::class)->replace('project', $version, [$source], [$node], []);

    return $database;
}

function outcomeOf(array $document, string $id): string
{
    return checkRow($document, $id)['outcome'];
}

function reasonOf(array $document, string $id): string
{
    return checkRow($document, $id)['reason_code'];
}

function checkRow(array $document, string $id): array
{
    foreach ($document['checks'] as $check) {
        if ($check['check_id'] === $id) {
            return $check;
        }
    }

    throw new RuntimeException('Missing '.$id);
}

function attemptPath(string $directory, array $document, string $id, int $attempt): string
{
    return $directory.'/runs/'.$document['run_id'].'/checks/'.$id.'/attempt-'.$attempt.'.json';
}

afterEach(function () {
    config()->set('molly.knowledge.database', '.molly/knowledge.sqlite');
    $previous = $this->mollyHomeBefore;
    if (! is_string($previous) || $previous === '') {
        putenv('MOLLY_HOME');
        unset($_ENV['MOLLY_HOME'], $_SERVER['MOLLY_HOME']);
    } else {
        putenv('MOLLY_HOME='.$previous);
        $_ENV['MOLLY_HOME'] = $previous;
        $_SERVER['MOLLY_HOME'] = $previous;
    }
    foreach ($this->verificationDirectories ?? [] as $directory) {
        File::deleteDirectory($directory);
    }
});

it('collects the same programmatic evidence from the command and MCP', function () {
    $fixture = bloomFixture();
    bindPermissions(PermissionState::Granted, PermissionState::Granted);
    $sha = verificationSha();

    [$exit, $cli] = mollyJson('molly:verify', [
        '--permissionless' => true,
        '--candidate' => $sha,
        '--evidence' => $fixture['evidence'],
        '--project' => $fixture['project'],
    ]);
    $mcpEvidence = verificationDirectory();
    $mcp = MollyServer::tool(MollyVerify::class, [
        'operation' => 'run_permissionless',
        'evidence' => $mcpEvidence,
        'candidate' => $sha,
        'project' => $fixture['project'],
    ]);
    $mcp->assertOk();
    $method = new ReflectionMethod($mcp, 'structuredContent');
    $method->setAccessible(true);
    $document = $method->invoke($mcp);
    expect($document)->toBeArray();

    expect($exit)->toBe(3)
        ->and($cli['checks'])->toHaveCount(16)
        ->and($document['checks'])->toHaveCount(16)
        ->and($cli['release_complete'])->toBeFalse()
        ->and($document['native_observation'])->toBe('disabled_by_mode')
        ->and(outcomeOf($cli, 'M04.4'))->toBe('PASS')
        ->and(outcomeOf($document, 'M04.4'))->toBe(outcomeOf($cli, 'M04.4'))
        ->and(reasonOf($document, 'M04.9'))->toBe(reasonOf($cli, 'M04.9'))
        ->and(checkRow($cli, 'M04.4')['assertions'][0]['expected'])->toBe('one task')
        ->and(checkRow($cli, 'M04.4')['assertions'][0]['source_artifact'])->toBe('database:molly_tasks')
        ->and(checkRow($document, 'M04.4')['assertions'][0]['actual'])->toBe(checkRow($cli, 'M04.4')['assertions'][0]['actual']);
});

it('collects the same host telemetry from the command and MCP', function () {
    $fixture = bloomFixture();
    $plugins = bloomPluginDirectory();
    $database = seedTaskGraph($fixture['project']);
    $inspector = new BloomHostInspector(bloomProcessListing(), $plugins);
    $observer = new CompiledHostBloomObserver;
    $prompt = new class(new MacOsTccProbe) extends MacOsPermissionPrompt
    {
        public int $requests = 0;

        public int $settings = 0;

        public function request(VerifierPermissionSnapshot $before): bool
        {
            $this->requests++;

            return false;
        }

        public function openSettings(VerifierPermissionSnapshot $after): array
        {
            $this->settings++;

            return ['settings'];
        }
    };
    app()->instance(BloomHostInspector::class, $inspector);
    app()->instance(CompiledHostBloomObserver::class, $observer);
    app()->instance(MacOsPermissionPrompt::class, $prompt);
    bindPermissions(PermissionState::Denied, PermissionState::Denied);
    $sha = verificationSha();

    [$exit, $cli] = mollyJson('molly:verify', [
        '--permissionless' => true,
        '--candidate' => $sha,
        '--evidence' => $fixture['evidence'],
        '--project' => $fixture['project'],
    ]);
    $mcp = MollyServer::tool(MollyVerify::class, [
        'operation' => 'run_permissionless',
        'evidence' => verificationDirectory(),
        'candidate' => $sha,
        'project' => $fixture['project'],
    ]);
    $mcp->assertOk();
    $method = new ReflectionMethod($mcp, 'structuredContent');
    $method->setAccessible(true);
    $document = $method->invoke($mcp);

    expect($exit)->toBe(3)
        ->and($inspector->inspections)->toBe(2)
        ->and($observer->calls)->toBe(0)
        ->and($prompt->requests)->toBe(0)
        ->and($prompt->settings)->toBe(0)
        ->and($cli['native_checks_run'])->toBe([])
        ->and($document['native_checks_run'])->toBe([])
        ->and($cli['native_observation'])->toBe('disabled_by_mode')
        ->and($document['native_observation'])->toBe('disabled_by_mode')
        ->and($cli['preflight']['request_attempted'])->toBeFalse()
        ->and($cli['preflight']['settings_opened'])->toBe([])
        ->and($cli['preflight']['accessibility'])->toBe('denied')
        ->and($cli['host']['running'])->toBeTrue()
        ->and($cli['host']['pid'])->toBe(4242)
        ->and($cli['host']['plugin_id'])->toBe('sifrious.molly')
        ->and($cli['host']['enabled'])->toBeTrue()
        ->and($document['host'])->toBe($cli['host'])
        ->and($cli['release_complete'])->toBeFalse()
        ->and($document['release_complete'])->toBeFalse()
        ->and($cli['summary']['product_failures'])->toBe(0);

    foreach (['M04.1', 'M04.7', 'M04.10', 'M04.13', 'M04.15', 'M04.16'] as $id) {
        $left = checkRow($cli, $id);
        $right = checkRow($document, $id);
        expect($left['outcome'])->toBe('PASS')
            ->and($right['outcome'])->toBe('PASS')
            ->and($right['reason_code'])->toBe($left['reason_code'])
            ->and($left['assertions'])->not->toBeEmpty();
        foreach ($left['assertions'] as $index => $assertion) {
            expect($assertion['candidate_sha'])->toBe($sha)
                ->and($assertion['run_id'])->toBe($cli['run_id'])
                ->and($assertion['assertion'])->not->toBe('')
                ->and($assertion['expected'])->not->toBe('')
                ->and($assertion['actual'])->not->toBe('')
                ->and($assertion['source_artifact'])->not->toBe('')
                ->and($right['assertions'][$index]['expected'])->toBe($assertion['expected'])
                ->and($right['assertions'][$index]['actual'])->toBe($assertion['actual'])
                ->and($right['assertions'][$index]['source_artifact'])->toBe($assertion['source_artifact'])
                ->and($right['assertions'][$index]['candidate_sha'])->toBe($sha)
                ->and($right['assertions'][$index]['run_id'])->toBe($document['run_id']);
        }
    }

    expect(reasonOf($cli, 'M04.1'))->toBe('HOST_PLUGIN_DISCOVERED')
        ->and(reasonOf($cli, 'M04.2'))->toBe('NATIVE_OBSERVATION_DISABLED')
        ->and(reasonOf($document, 'M04.2'))->toBe('NATIVE_OBSERVATION_DISABLED')
        ->and(reasonOf($cli, 'M04.3'))->toBe('NATIVE_OBSERVATION_DISABLED')
        ->and(reasonOf($document, 'M04.3'))->toBe('NATIVE_OBSERVATION_DISABLED')
        ->and(reasonOf($cli, 'M04.12'))->toBe('NATIVE_OBSERVATION_DISABLED')
        ->and(reasonOf($document, 'M04.12'))->toBe('NATIVE_OBSERVATION_DISABLED')
        ->and(checkRow($cli, 'M04.7')['assertions'][0]['source_artifact'])->toBe($database)
        ->and(checkRow($cli, 'M04.7')['assertions'][0]['actual'])->toBe(checkRow($document, 'M04.7')['assertions'][0]['actual']);
});

it('reports a wrong plugin id as a product failure when accessibility is missing', function () {
    $fixture = bloomFixture();
    $observer = new CompiledHostBloomObserver;
    app()->instance(BloomHostInspector::class, new BloomHostInspector(bloomProcessListing(), bloomPluginDirectory('other.plugin')));
    app()->instance(CompiledHostBloomObserver::class, $observer);
    bindPermissions(PermissionState::Granted, PermissionState::Denied);

    $document = app(VerifyMolly::class)->verify(
        VerificationMode::Permissionless,
        $fixture['evidence'],
        verificationSha(),
        null,
        $fixture['project'],
    );

    expect(outcomeOf($document, 'M04.1'))->toBe('PRODUCT_FAIL')
        ->and(reasonOf($document, 'M04.1'))->toBe('HOST_PLUGIN_NOT_DISCOVERED')
        ->and(checkRow($document, 'M04.1')['assertions'][2]['expected'])->toBe('sifrious.molly apiVersion 1 enabled')
        ->and(checkRow($document, 'M04.1')['assertions'][2]['actual'])->toContain('other.plugin')
        ->and(outcomeOf($document, 'M04.4'))->toBe('PASS')
        ->and(outcomeOf($document, 'M04.10'))->toBe('PASS')
        ->and(reasonOf($document, 'M04.2'))->toBe('NATIVE_OBSERVATION_DISABLED')
        ->and($observer->calls)->toBe(0)
        ->and($document['preflight']['request_attempted'])->toBeFalse()
        ->and($document['preflight']['settings_opened'])->toBe([])
        ->and($document['preflight']['accessibility'])->toBe('denied')
        ->and($document['summary']['product_failures'])->toBe(1)
        ->and($document['exit_code'])->toBe(1)
        ->and($document['release_complete'])->toBeFalse();
});

it('does not accept a host telemetry file that claims the plugin was discovered', function () {
    $fixture = bloomFixture();
    $empty = verificationDirectory().'/Plugins';
    mkdir($empty, 0700, true);
    file_put_contents($fixture['project'].'/host-telemetry.json', json_encode([
        'plugin_discovered' => true,
        'plugin_id' => 'sifrious.molly',
        'outcome' => 'PASS',
    ], JSON_THROW_ON_ERROR));
    app()->instance(BloomHostInspector::class, new BloomHostInspector("999 /usr/bin/php /tmp/bloom-plugin/build.php\n", $empty));
    bindPermissions(PermissionState::Granted, PermissionState::Granted);
    $context = new VerificationContext(
        verificationSha(),
        '',
        PackageRoot::path(),
        $fixture['evidence'],
        VerificationMode::Permissionless,
        $fixture['project'].'/host-telemetry.json',
        [
            'M04.1' => [
                'disposition' => 'complete',
                'outcome' => CheckOutcome::Pass->value,
                'reason_code' => 'PROGRAMMATIC_PASS',
                'message' => 'caller said pass',
            ],
        ],
        [],
        [],
        [],
        $fixture['project'],
    );

    $document = app(VerifyMolly::class)->verify(
        VerificationMode::Permissionless,
        $fixture['evidence'],
        verificationSha(),
        $context,
    );

    expect(outcomeOf($document, 'M04.1'))->toBe('BLOCKED_PREREQUISITE')
        ->and(reasonOf($document, 'M04.1'))->toBe('COMPILED_HOST_NOT_OBSERVED')
        ->and($document['host']['running'])->toBeFalse()
        ->and($document['release_complete'])->toBeFalse();
});

it('does not drive native UI in permissionless mode when both permissions are granted', function () {
    $fixture = bloomFixture();
    $observer = new CompiledHostBloomObserver;
    $prompt = new class(new MacOsTccProbe) extends MacOsPermissionPrompt
    {
        public int $requests = 0;

        public int $settings = 0;

        public function request(VerifierPermissionSnapshot $before): bool
        {
            $this->requests++;

            return true;
        }

        public function openSettings(VerifierPermissionSnapshot $after): array
        {
            $this->settings++;

            return ['settings'];
        }
    };
    app()->instance(CompiledHostBloomObserver::class, $observer);
    app()->instance(MacOsPermissionPrompt::class, $prompt);
    bindPermissions(PermissionState::Granted, PermissionState::Granted);

    $document = app(VerifyMolly::class)->verify(
        VerificationMode::Permissionless,
        $fixture['evidence'],
        verificationSha(),
        null,
        $fixture['project'],
    );

    expect($observer->calls)->toBe(0)
        ->and($prompt->requests)->toBe(0)
        ->and($prompt->settings)->toBe(0)
        ->and($document['native_checks_run'])->toBe([])
        ->and($document['preflight']['request_attempted'])->toBeFalse()
        ->and($document['preflight']['settings_opened'])->toBe([])
        ->and(reasonOf($document, 'M04.2'))->toBe('NATIVE_OBSERVATION_DISABLED')
        ->and(reasonOf($document, 'M04.3'))->toBe('NATIVE_OBSERVATION_DISABLED')
        ->and(reasonOf($document, 'M04.12'))->toBe('NATIVE_OBSERVATION_DISABLED')
        ->and(reasonOf($document, 'M04.1'))->toBe('COMPILED_HOST_NOT_OBSERVED')
        ->and(reasonOf($document, 'M04.16'))->toBe('RESTART_HOST_NOT_OBSERVED')
        ->and(reasonOf($document, 'M04.13'))->toBe('HOST_DIAGNOSTIC_NOT_OBSERVED')
        ->and(outcomeOf($document, 'M04.4'))->toBe('PASS')
        ->and($document['release_complete'])->toBeFalse()
        ->and($document['summary']['product_failures'])->toBe(0);
});

it('still runs independent assertions when accessibility is missing', function () {
    $fixture = bloomFixture();
    bindPermissions(PermissionState::Granted, PermissionState::Denied);

    $document = app(VerifyMolly::class)->verify(
        VerificationMode::Default,
        $fixture['evidence'],
        verificationSha(),
        null,
        $fixture['project'],
    );

    expect(outcomeOf($document, 'M04.2'))->toBe('BLOCKED_VERIFIER_PERMISSION')
        ->and(outcomeOf($document, 'M04.3'))->toBe('BLOCKED_VERIFIER_PERMISSION')
        ->and(outcomeOf($document, 'M04.10'))->toBe('BLOCKED_PREREQUISITE')
        ->and(reasonOf($document, 'M04.10'))->toBe('WORKER_HOST_NOT_OBSERVED')
        ->and(outcomeOf($document, 'M04.4'))->toBe('PASS')
        ->and(outcomeOf($document, 'M04.9'))->toBe('PASS')
        ->and($document['summary']['product_failures'])->toBe(0)
        ->and($document['release_complete'])->toBeFalse()
        ->and($document['checks'])->toHaveCount(16);
});

it('keeps running programmatic checks when both permissions are missing', function () {
    $fixture = bloomFixture();
    bindPermissions(PermissionState::NotDetermined, PermissionState::Denied);

    $document = app(VerifyMolly::class)->verify(
        VerificationMode::Default,
        $fixture['evidence'],
        verificationSha(),
        null,
        $fixture['project'],
    );

    expect(outcomeOf($document, 'M04.4'))->toBe('PASS')
        ->and(outcomeOf($document, 'M04.14'))->toBe('PASS')
        ->and(outcomeOf($document, 'M04.2'))->toBe('BLOCKED_VERIFIER_PERMISSION')
        ->and(outcomeOf($document, 'M04.1'))->toBe('BLOCKED_PREREQUISITE')
        ->and($document['summary']['product_failures'])->toBe(0)
        ->and($document['summary']['passes'])->toBeGreaterThan(0)
        ->and($document['release_complete'])->toBeFalse();
});

it('runs independent assertions when the platform cannot observe TCC', function () {
    $fixture = bloomFixture();
    bindPermissions(PermissionState::Unsupported, PermissionState::Unsupported, PHP_OS_FAMILY);

    $document = app(VerifyMolly::class)->verify(
        VerificationMode::Default,
        $fixture['evidence'],
        verificationSha(),
        null,
        $fixture['project'],
    );

    expect($document['preflight']['platform'])->toBe(PHP_OS_FAMILY)
        ->and($document['preflight']['screen_recording'])->toBe('unsupported')
        ->and($document['preflight']['accessibility'])->toBe('unsupported')
        ->and($document['preflight']['request_attempted'])->toBeFalse()
        ->and($document['preflight']['settings_opened'])->toBe([])
        ->and($document['checks'])->toHaveCount(16)
        ->and(outcomeOf($document, 'M04.4'))->toBe('PASS')
        ->and(outcomeOf($document, 'M04.2'))->toBe('BLOCKED_VERIFIER_PERMISSION')
        ->and($document['summary']['product_failures'])->toBe(0)
        ->and($document['permission_granted_by_cli'])->toBeFalse()
        ->and($document['release_complete'])->toBeFalse();
});

it('continues independent checks when permission inspection fails', function () {
    $fixture = bloomFixture('other-model');
    app()->instance(VerifierPermissionInspector::class, new class implements VerifierPermissionInspector
    {
        public function inspect(): VerifierPermissionSnapshot
        {
            throw new RuntimeException('swift unavailable');
        }
    });

    $document = app(VerifyMolly::class)->verify(
        VerificationMode::Permissionless,
        $fixture['evidence'],
        verificationSha(),
        null,
        $fixture['project'],
    );

    expect($document['checks'])->toHaveCount(16)
        ->and($document['preflight']['inspection_status'])->toBe('not_observed')
        ->and($document['preflight']['screen_recording'])->toBe('unknown')
        ->and($document['preflight']['accessibility'])->toBe('unknown')
        ->and($document['preflight']['request_attempted'])->toBeFalse()
        ->and(outcomeOf($document, 'M04.9'))->toBe('PRODUCT_FAIL')
        ->and(outcomeOf($document, 'M04.4'))->toBe('PASS')
        ->and(reasonOf($document, 'M04.2'))->toBe('NATIVE_OBSERVATION_DISABLED')
        ->and($document['summary']['product_failures'])->toBe(1)
        ->and($document['exit_code'])->toBe(1)
        ->and($document['permission_granted_by_cli'])->toBeFalse();
});

it('reports a programmatic defect as a product failure when accessibility is missing', function () {
    $fixture = bloomFixture('other-model');
    bindPermissions(PermissionState::Granted, PermissionState::NotDetermined);

    $document = app(VerifyMolly::class)->verify(
        VerificationMode::Permissionless,
        $fixture['evidence'],
        verificationSha(),
        null,
        $fixture['project'],
    );

    expect(outcomeOf($document, 'M04.9'))->toBe('PRODUCT_FAIL')
        ->and(reasonOf($document, 'M04.9'))->toBe('SETTINGS_MISMATCH')
        ->and(checkRow($document, 'M04.9')['assertions'][0]['expected'])->toBe('other-model')
        ->and(checkRow($document, 'M04.9')['assertions'][0]['actual'])->toBe('local-test-model')
        ->and(outcomeOf($document, 'M04.2'))->toBe('EVIDENCE_INCOMPLETE')
        ->and($document['summary']['product_failures'])->toBe(1)
        ->and($document['exit_code'])->toBe(1);
});

it('shows run status and trace ids when the provider refuses before verification', function () {
    $fixture = bloomFixture();
    $run = Run::query()->firstOrFail();
    $run->update([
        'status' => 'failed',
        'report' => [
            'error' => 'LOCAL_PROVIDER_INVALID: Use a local Ollama model and a loopback HTTP URL.',
            'terminated_before_completion' => true,
            'changes' => [],
            'verification_outcomes' => [
                'pest' => ['state' => 'NOT_RUN', 'policy' => 'required', 'failure_action' => 'retry'],
            ],
            'verification_receipts' => [[
                'verifier' => 'pest',
                'state' => 'NOT_RUN',
                'evidence_digest' => str_repeat('ab', 32),
                'path' => $fixture['project'].'/.molly/receipts/'.$run->id.'/pest.json',
            ]],
        ],
    ]);
    bindPermissions(PermissionState::Granted, PermissionState::NotDetermined);

    $document = app(VerifyMolly::class)->verify(
        VerificationMode::Permissionless,
        $fixture['evidence'],
        verificationSha(),
        null,
        $fixture['project'],
    );

    expect(outcomeOf($document, 'M04.5'))->toBe('PASS')
        ->and(reasonOf($document, 'M04.5'))->toBe('RUN_STATUS_SHOWN')
        ->and(checkRow($document, 'M04.5')['assertions'][0]['actual'])->toBe('failed verification not_run LOCAL_PROVIDER_INVALID')
        ->and(outcomeOf($document, 'M04.11'))->toBe('PASS')
        ->and(reasonOf($document, 'M04.11'))->toBe('TRACE_IDS_MATCH')
        ->and($document['preflight']['request_attempted'])->toBeFalse()
        ->and($document['preflight']['settings_opened'])->toBe([])
        ->and($document['summary']['product_failures'])->toBe(0)
        ->and($document['release_complete'])->toBeFalse()
        ->and($document['checks'])->toHaveCount(16);
});

it('keeps a mismatched diff id as a product failure', function () {
    $fixture = bloomFixture();
    $run = Run::query()->firstOrFail();
    $report = $run->report;
    $report['diff']['run_id'] = 'other-run';
    $run->update(['report' => $report]);
    bindPermissions(PermissionState::Granted, PermissionState::Granted);

    $document = app(VerifyMolly::class)->verify(
        VerificationMode::Permissionless,
        $fixture['evidence'],
        verificationSha(),
        null,
        $fixture['project'],
    );

    expect(outcomeOf($document, 'M04.5'))->toBe('PASS')
        ->and(outcomeOf($document, 'M04.11'))->toBe('PRODUCT_FAIL')
        ->and(reasonOf($document, 'M04.11'))->toBe('TRACE_IDS_DIVERGE')
        ->and($document['summary']['product_failures'])->toBe(1)
        ->and($document['exit_code'])->toBe(1)
        ->and($document['release_complete'])->toBeFalse();
});

it('does not treat a run without a verifier record as shown', function () {
    $fixture = bloomFixture();
    $run = Run::query()->firstOrFail();
    $run->update([
        'status' => 'failed',
        'report' => [
            'error' => 'LOCAL_PROVIDER_INVALID: Use a local Ollama model and a loopback HTTP URL.',
        ],
    ]);
    bindPermissions(PermissionState::Granted, PermissionState::NotDetermined);

    $document = app(VerifyMolly::class)->verify(
        VerificationMode::Permissionless,
        $fixture['evidence'],
        verificationSha(),
        null,
        $fixture['project'],
    );

    expect(outcomeOf($document, 'M04.5'))->toBe('PRODUCT_FAIL')
        ->and(reasonOf($document, 'M04.5'))->toBe('RUN_STATUS_MISSING')
        ->and($document['summary']['product_failures'])->toBeGreaterThan(0)
        ->and($document['release_complete'])->toBeFalse();
});

it('reports a broken fixture as a harness failure', function () {
    $fixture = bloomFixture();
    bindPermissions(PermissionState::Granted, PermissionState::Granted);
    $context = new VerificationContext(
        verificationSha(),
        '',
        PackageRoot::path(),
        $fixture['evidence'],
        VerificationMode::Permissionless,
        null,
        [],
        [],
        ['M04.4'],
        [],
        $fixture['project'],
    );

    $document = app(VerifyMolly::class)->verify(
        VerificationMode::Permissionless,
        $fixture['evidence'],
        verificationSha(),
        $context,
    );

    expect(outcomeOf($document, 'M04.4'))->toBe('HARNESS_FAIL')
        ->and(outcomeOf($document, 'M04.9'))->toBe('PASS')
        ->and($document['summary']['harness_failures'])->toBe(1)
        ->and($document['summary']['product_failures'])->toBe(0)
        ->and($document['exit_code'])->toBe(2);
});

it('keeps previous results when a retry inspection fails', function () {
    $fixture = bloomFixture();
    bindPermissions(PermissionState::Denied, PermissionState::Granted);
    $first = app(VerifyMolly::class)->verify(
        VerificationMode::Default,
        $fixture['evidence'],
        verificationSha(),
        null,
        $fixture['project'],
    );
    $blocked = file_get_contents(attemptPath($fixture['evidence'], $first, 'M04.12', 1));
    $passed = file_get_contents(attemptPath($fixture['evidence'], $first, 'M04.4', 1));
    $preflight = file_get_contents($fixture['evidence'].'/runs/'.$first['run_id'].'/preflight-attempt-1.json');
    app()->instance(VerifierPermissionInspector::class, new class implements VerifierPermissionInspector
    {
        public function inspect(): VerifierPermissionSnapshot
        {
            throw new RuntimeException('swift timed out');
        }
    });

    $retry = app(VerifyMolly::class)->verify(
        VerificationMode::RetryNativeUi,
        $fixture['evidence'],
        verificationSha(),
        null,
        $fixture['project'],
    );

    expect(outcomeOf($first, 'M04.12'))->toBe('BLOCKED_VERIFIER_PERMISSION')
        ->and(outcomeOf($first, 'M04.4'))->toBe('PASS')
        ->and($retry['checks'])->toHaveCount(16)
        ->and(outcomeOf($retry, 'M04.4'))->toBe('PASS')
        ->and(outcomeOf($retry, 'M04.12'))->toBe('BLOCKED_VERIFIER_PERMISSION')
        ->and($retry['retried_checks'])->toBe([])
        ->and($retry['preflight_diagnostic']['inspection_status'])->toBe('not_observed')
        ->and($retry['exit_code'])->toBe(2)
        ->and(file_get_contents(attemptPath($fixture['evidence'], $first, 'M04.12', 1)))->toBe($blocked)
        ->and(file_get_contents(attemptPath($fixture['evidence'], $first, 'M04.4', 1)))->toBe($passed)
        ->and(is_file(attemptPath($fixture['evidence'], $first, 'M04.4', 2)))->toBeFalse()
        ->and(is_file(attemptPath($fixture['evidence'], $first, 'M04.12', 2)))->toBeFalse()
        ->and(file_get_contents($fixture['evidence'].'/runs/'.$first['run_id'].'/preflight-attempt-1.json'))->toBe($preflight);
});

it('rejects stale evidence when the candidate SHA changed', function () {
    $directory = verificationDirectory();
    bindPermissions(PermissionState::Denied, PermissionState::Granted);
    $first = app(VerifyMolly::class)->verify(VerificationMode::Default, $directory, verificationSha());
    $before = hash_file('sha256', attemptPath($directory, $first, 'M04.1', 1));

    $retry = app(VerifyMolly::class)->verify(VerificationMode::RetryNativeUi, $directory, str_repeat('cd', 20));

    expect($retry['exit_code'])->toBe(4)
        ->and($retry['reason_code'])->toBe('CANDIDATE_SHA_MISMATCH')
        ->and($retry['reused_evidence'])->toBeFalse()
        ->and(hash_file('sha256', attemptPath($directory, $first, 'M04.1', 1)))->toBe($before)
        ->and(is_file(attemptPath($directory, $first, 'M04.1', 2)))->toBeFalse();
});

it('rejects stale evidence when the verifier environment changed', function () {
    $directory = verificationDirectory();
    bindPermissions(PermissionState::Granted, PermissionState::Granted);
    $first = app(VerifyMolly::class)->verify(VerificationMode::Permissionless, $directory, verificationSha());
    $path = $directory.'/runs/'.$first['run_id'].'/result.json';
    $saved = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $saved['environment']['os_family'] = PHP_OS_FAMILY === 'Darwin' ? 'Linux' : 'Darwin';
    file_put_contents($path, json_encode($saved, JSON_THROW_ON_ERROR));
    $before = hash_file('sha256', $path);

    $retry = app(VerifyMolly::class)->verify(VerificationMode::RetryNativeUi, $directory, verificationSha());

    expect($retry['exit_code'])->toBe(4)
        ->and($retry['reason_code'])->toBe('ENVIRONMENT_CHANGED')
        ->and(hash_file('sha256', $path))->toBe($before);
});

it('does not let saved permission history override the current inspection', function () {
    $directory = verificationDirectory();
    file_put_contents($directory.'/permission-history.json', json_encode([
        'schema' => 'molly.verifier-permission-history/1',
        'entries' => [[
            'screen_recording' => 'granted',
            'accessibility' => 'granted',
            'disposition' => 'granted',
        ]],
    ], JSON_THROW_ON_ERROR));
    bindPermissions(PermissionState::Denied, PermissionState::Denied);

    $document = app(VerifyMolly::class)->inspectPermissions($directory, verificationSha());

    expect($document['screen_recording'])->toBe('denied')
        ->and($document['accessibility'])->toBe('denied')
        ->and($document['previous_disposition'])->toBe('granted')
        ->and($document['changed_since_previous'])->toBeTrue()
        ->and($document['permission_granted_by_cli'])->toBeFalse()
        ->and($document['exit_code'])->toBe(0);
});

it('does not accept a caller supplied pass record', function () {
    $directory = verificationDirectory();
    bindPermissions(PermissionState::Granted, PermissionState::Granted);
    $context = new VerificationContext(verificationSha(), '', PackageRoot::path(), $directory, VerificationMode::Permissionless, null, [
        'M04.4' => [
            'disposition' => 'complete',
            'outcome' => CheckOutcome::Pass->value,
            'reason_code' => 'PROGRAMMATIC_PASS',
            'message' => 'caller said pass',
        ],
    ]);

    $document = app(VerifyMolly::class)->verify(VerificationMode::Permissionless, $directory, verificationSha(), $context);

    expect(outcomeOf($document, 'M04.4'))->not->toBe('PASS')
        ->and(reasonOf($document, 'M04.4'))->toBe('TASK_NOT_OBSERVED');
});

it('does not hide a broken plugin seam behind a missing permission', function () {
    $directory = verificationDirectory();
    $root = $directory.'/empty-repo';
    mkdir($root, 0700, true);
    bindPermissions(PermissionState::Denied, PermissionState::Denied);
    $context = new VerificationContext(verificationSha(), '', $root, $directory, VerificationMode::Permissionless);

    $document = app(VerifyMolly::class)->verify(VerificationMode::Permissionless, $directory, verificationSha(), $context);

    expect(outcomeOf($document, 'M04.1'))->toBe('PRODUCT_FAIL')
        ->and($document['exit_code'])->toBe(1)
        ->and($document['summary']['product_failures'])->toBe(1);
});

it('does not claim a grant when the permission observation is unsupported', function () {
    $directory = verificationDirectory();
    bindPermissions(PermissionState::Unsupported, PermissionState::Unsupported, PHP_OS_FAMILY);

    $document = app(VerifyMolly::class)->verify(VerificationMode::Default, $directory, verificationSha());

    expect($document['preflight']['screen_recording'])->toBe('unsupported')
        ->and($document['preflight']['accessibility'])->toBe('unsupported')
        ->and($document['permission_granted_by_cli'])->toBeFalse()
        ->and($document['preflight']['request_attempted'])->toBeFalse()
        ->and($document['preflight']['settings_opened'])->toBe([])
        ->and($document['checks'])->toHaveCount(16)
        ->and($document['summary']['product_failures'])->toBe(0)
        ->and(outcomeOf($document, 'M04.1'))->toBe('BLOCKED_PREREQUISITE')
        ->and($document['release_complete'])->toBeFalse();
});

it('does not claim the CLI granted a permission the probe did not request', function () {
    $prompt = new StubPermissionPrompt(false);
    $inspector = new SequencePermissionInspector([
        permissionSnapshot(PermissionState::Granted, PermissionState::Granted),
    ]);

    $result = (new MacOsVerifierPermissionRequester($inspector, $prompt))->requestMissing(
        permissionSnapshot(PermissionState::NotDetermined, PermissionState::NotDetermined),
    );

    expect($prompt->requested)->toBeFalse()
        ->and($result->requestAttempted)->toBeFalse()
        ->and($result->permissionGrantedByCli)->toBeFalse()
        ->and($result->settingsOpened)->toBe([])
        ->and($result->after->screenRecording)->toBe(PermissionState::Granted);
});

it('records a grant only from the inspector after a request, and opens settings when macOS does not grant it', function () {
    $prompt = new StubPermissionPrompt;
    $granted = new SequencePermissionInspector([permissionSnapshot(PermissionState::Granted, PermissionState::Granted)]);
    $grantedResult = (new MacOsVerifierPermissionRequester($granted, $prompt))->requestMissing(
        permissionSnapshot(PermissionState::NotDetermined, PermissionState::Granted),
    );
    $deniedPrompt = new StubPermissionPrompt;
    $denied = new SequencePermissionInspector([permissionSnapshot(PermissionState::NotDetermined, PermissionState::Granted)]);
    $deniedResult = (new MacOsVerifierPermissionRequester($denied, $deniedPrompt))->requestMissing(
        permissionSnapshot(PermissionState::NotDetermined, PermissionState::Granted),
    );

    expect($grantedResult->permissionGrantedByCli)->toBeTrue()
        ->and($grantedResult->settingsOpened)->toBe([])
        ->and($deniedResult->permissionGrantedByCli)->toBeFalse()
        ->and($deniedResult->after->screenRecording)->toBe(PermissionState::Denied)
        ->and($deniedResult->settingsOpened)->toBe([PermissionKind::ScreenRecording->settingsUrl()])
        ->and($deniedPrompt->requested)->toBeTrue();
});

it('serves permission inspection through the command and MCP', function () {
    $directory = verificationDirectory();
    $sha = verificationSha();
    $observed = app(MacOsVerifierPermissionInspector::class)->inspect();

    [$exit, $json] = mollyJson('molly:verify', [
        '--check-permissions' => true,
        '--candidate' => $sha,
        '--evidence' => $directory,
    ]);
    $conflict = mollyJson('molly:verify', [
        '--permissionless' => true,
        '--retry-native-ui' => true,
        '--candidate' => $sha,
        '--evidence' => $directory,
    ]);
    MollyServer::tools()->assertRegistered([MollyVerify::class]);
    $inspected = MollyServer::tool(MollyVerify::class, [
        'operation' => 'inspect_permissions',
        'evidence' => $directory,
        'candidate' => $sha,
    ]);

    expect($exit)->toBe(0)
        ->and($json['screen_recording'])->toBe($observed->screenRecording->value)
        ->and($json['accessibility'])->toBe($observed->accessibility->value)
        ->and($json['request_attempted'])->toBeFalse()
        ->and($json['settings_opened'])->toBe([])
        ->and($json['permission_granted_by_cli'])->toBeFalse()
        ->and($conflict[0])->toBe(4)
        ->and($conflict[1]['reason_code'])->toBe('VERIFICATION_MODE_CONFLICT');
    $inspected->assertOk()->assertSee([
        $observed->screenRecording->value,
        $observed->accessibility->value,
        'permission_granted_by_cli',
    ]);
});

it('does not ship a TCC reset or a CLI grant', function () {
    $source = '';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 3).'/src/Acceptance'));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $source .= file_get_contents($file->getPathname());
        }
    }

    expect($source)->not->toContain('tccutil')
        ->and($source)->not->toContain('TCC.db')
        ->and($source)->not->toContain('csrutil');
});
