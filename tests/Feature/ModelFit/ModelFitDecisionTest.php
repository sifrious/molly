<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Sifrious\Molly\Actions\DecideModelFit;
use Sifrious\Molly\Actions\InspectHardware;
use Sifrious\Molly\Agents\LocalOllama;
use Sifrious\Molly\Hardware\HardwareSnapshot;
use Sifrious\Molly\ModelFit\InstallationHeadroom;
use Sifrious\Molly\ModelFit\MachineFacts;
use Sifrious\Molly\ModelFit\ModelFitRecommender;
use Sifrious\Molly\ModelFit\ModelFitStatus;
use Sifrious\Molly\ModelFit\ModelRecords;
use Sifrious\Molly\ModelFit\TrustedModelCatalogue;
use Sifrious\Molly\Settings\SettingsStore;

/*
 * Each test replays a simulated host from tests/Fixtures/hardware through the real probe,
 * then asks DecideModelFit for the fit decision against the bundled catalogue. MOLLY_HOME
 * is a temporary directory, so readiness records exist only when a test writes them.
 */

beforeEach(function () {
    $this->home = sys_get_temp_dir().'/molly-fit-home-'.bin2hex(random_bytes(6));
    app()->instance(SettingsStore::class, new SettingsStore($this->home));
});

afterEach(function () {
    File::deleteDirectory($this->home);
});

/** @return array{0: array<string, mixed>, 1: array<string, mixed>} The snapshot and the decision. */
function decideFixture(string $name, array $commands = [], array $http = []): array
{
    $fixture = replayHardwareFixture($name, $commands, $http);
    $snapshot = app(InspectHardware::class)->handle($fixture['destination']);

    return [$snapshot, app(DecideModelFit::class)->handle($snapshot)];
}

function fitCandidate(array $decision, string $model): array
{
    return collect($decision['candidates'])->firstWhere('model', $model) ?? throw new RuntimeException("No candidate {$model}");
}

/** An Ollama /api/tags response listing these models. */
function tagsResponse(array $models): array
{
    return ['status' => 200, 'json' => ['models' => array_map(fn (array $model): array => ['name' => $model[0], 'model' => $model[0], 'digest' => $model[1], 'size' => $model[2] ?? 1], $models)]];
}

function vmStatWithFreePages(int $pages): array
{
    return commandOutput("Mach Virtual Memory Statistics: (page size of 16384 bytes)\nPages free: {$pages}.\nPages active: 1000.\nPages inactive: 0.\nPages speculative: 0.\nPages wired down: 1000.\nPages purgeable: 0.\nPages occupied by compressor: 0.\n");
}

/** A readiness record that passed for this model, digest, and runtime version. */
function passedReadiness(string $model, string $digest, string $runtime = '0.34.4'): void
{
    app(ModelRecords::class)->recordReadiness($model, [
        'model' => $model, 'digest' => 'sha256:'.$digest, 'runtime' => ['name' => 'ollama', 'version' => $runtime], 'passed' => true,
    ]);
}

dataset('fixture outcomes', [
    'M3 Ultra 96 GB: recommended fit' => ['m3-ultra-96gb', 'recommended_fit', 'gpt-oss:120b-code', ['recommended_memory_available'], []],
    '16 GB M-series: minimum fit with constraints' => ['m2-pro-16gb-external', 'minimum_fit', 'gpt-oss:20b', ['minimum_requirements_met', 'artifact_installed_unverified'], ['memory_headroom_below_budget']],
    '8 GB MacBook Air: no fit' => ['macbook-air-8gb', 'no_fit', null, ['insufficient_memory', 'insufficient_disk'], []],
    'Intel Mac: unsupported' => ['intel-mac-x86_64', 'unsupported', null, ['platform_unsupported:intel'], []],
    'Linux: unsupported' => ['linux-x86_64', 'unsupported', null, ['platform_unsupported:linux'], []],
    'macOS 13 on Apple silicon: unsupported' => ['apple-silicon-macos-13', 'unsupported', null, ['os_version_unsupported'], []],
    'every probe failed: unknown' => ['probes-unknown', 'unknown', null, ['compatibility_unknown:platform.macos', 'runtime_prerequisite_unknown:metal', 'compatibility_unknown:memory.total_bytes', 'compatibility_unknown:disk.free_bytes', 'compatibility_unknown:ollama.installed_models'], []],
    'Rosetta translated: downgraded' => ['rosetta-translated', 'minimum_fit', 'gpt-oss:20b', ['minimum_requirements_met', 'artifact_installed_unverified'], ['process_translated']],
    'critical memory pressure: downgraded' => ['high-memory-pressure', 'minimum_fit', 'gpt-oss:20b', ['minimum_requirements_met', 'artifact_installed_unverified'], ['memory_headroom_below_budget', 'memory_pressure_critical']],
    'removed external volume: only the installed model fits' => ['external-volume-removed', 'recommended_fit', 'gpt-oss:20b', ['recommended_memory_available', 'artifact_installed_unverified'], []],
]);

it('reaches the expected outcome for each simulated host', function (string $fixture, string $status, ?string $model, array $reasons, array $constraints) {
    [$snapshot, $decision] = decideFixture($fixture);

    expect($decision['schema'])->toBe('molly.model-fit-decision/1')
        ->and($decision['snapshot_sha256'])->toBe($snapshot['snapshot_sha256'])
        ->and($decision['status'])->toBe($status)
        ->and($decision['model_selection']['model'] ?? null)->toBe($model)
        ->and($decision['reasons'])->toBe($reasons)
        ->and($decision['constraints'])->toBe($constraints)
        ->and($decision['decision_sha256'])->toBe(HardwareSnapshot::hash(array_diff_key($decision, ['decision_sha256' => true])));
})->with('fixture outcomes');

it('prints the exact no-fit message with what it measured, what each model needs, and why', function () {
    [, $decision] = decideFixture('macbook-air-8gb');

    expect($decision['message'])->toBe('No supported local Ollama configuration fits this Mac.')
        ->and($decision['measured']['total_memory_bytes'])->toBe(8589934592)
        ->and(fitCandidate($decision, 'gpt-oss:20b'))->toMatchArray(['status' => 'no_fit', 'reasons' => ['insufficient_memory']])
        ->and(fitCandidate($decision, 'gpt-oss:20b')['required']['minimum_memory_bytes'])->toBe(17179869184)
        ->and(fitCandidate($decision, 'gpt-oss:120b-code')['required']['minimum_memory_bytes'])->toBe(103079215104)
        ->and($decision['install_allowed'])->toBeFalse();

    $fixture = replayHardwareFixture('macbook-air-8gb');
    $exit = Artisan::call('molly:preflight', ['--destination' => $fixture['destination']]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Model fit: no_fit. No supported local Ollama configuration fits this Mac.')
        ->toContain('Reasons: insufficient_memory, insufficient_disk')
        ->toContain('Molly will not install a model now: no_fit.');
});

it('reports unknown compatibility as unknown, never as incompatible', function () {
    [, $decision] = decideFixture('probes-unknown');

    expect($decision['status'])->toBe('unknown')
        ->and($decision['message'])->toContain('This is not a finding that the Mac is incompatible.')
        ->and($decision['message'])->not->toContain('No supported local Ollama configuration fits this Mac.')
        ->and(array_column($decision['candidates'], 'status'))->each->toBe('unknown')
        ->and($decision['install_allowed'])->toBeFalse();
});

it('keeps an unknown fact unknown when another fact is measured', function () {
    [, $decision] = decideFixture('m3-ultra-96gb', ['sysctl -n hw.memsize' => commandOutput('', 1, 'sysctl: unknown oid')]);

    expect($decision['status'])->toBe('unknown')
        ->and($decision['reasons'])->toBe(['compatibility_unknown:memory.total_bytes']);
});

it('reads Apple silicon from the arm64 capability when Rosetta translates the process', function () {
    [$snapshot, $decision] = decideFixture('rosetta-translated');

    expect(hardwareFact($snapshot, 'platform.architecture')['value'])->toBe('x86_64')
        ->and($decision['measured'])->toMatchArray(['apple_silicon' => true, 'translated' => true, 'architecture' => 'x86_64'])
        ->and(fitCandidate($decision, 'gpt-oss:20b')['fit'])->toBe('minimum_fit');

    [, $native] = decideFixture('rosetta-translated', ['sysctl -n sysctl.proc_translated' => commandOutput("0\n"), 'uname -m' => commandOutput("arm64\n")]);

    expect($native['status'])->toBe('recommended_fit')
        ->and($native['constraints'])->toBe([]);
});

dataset('memory thresholds', [
    '20b minimum, 1 byte short' => [17179869183, 'no_fit', null],
    '20b minimum, exact' => [17179869184, 'minimum_fit', 'gpt-oss:20b'],
    '20b minimum, 1 byte over' => [17179869185, 'minimum_fit', 'gpt-oss:20b'],
    '20b with headroom, 1 byte short' => [25769803775, 'minimum_fit', 'gpt-oss:20b'],
    '20b with headroom, exact' => [25769803776, 'recommended_fit', 'gpt-oss:20b'],
    '20b with headroom, 1 byte over' => [25769803777, 'recommended_fit', 'gpt-oss:20b'],
    '120b-code minimum, 1 byte short' => [103079215103, 'recommended_fit', 'gpt-oss:20b'],
    '120b-code minimum, exact' => [103079215104, 'recommended_fit', 'gpt-oss:120b-code'],
    '120b-code minimum, 1 byte over' => [103079215105, 'recommended_fit', 'gpt-oss:120b-code'],
]);

it('decides the same way at each memory threshold and one byte either side', function (int $memory, string $status, ?string $model) {
    [, $decision] = decideFixture('m3-ultra-96gb', ['sysctl -n hw.memsize' => commandOutput("{$memory}\n")], ['/api/tags' => tagsResponse([])]);

    expect($decision['status'])->toBe($status)
        ->and($decision['model_selection']['model'] ?? null)->toBe($model)
        ->and($decision['measured']['total_memory_bytes'])->toBe($memory);
})->with('memory thresholds');

it('uses molly.memory.headroom_gb for the recommended tier, the same rule a run uses before loading a model', function () {
    config(['molly.memory.headroom_gb' => 15]);
    $needed = 13793441244 + 15_000_000_000;

    [, $short] = decideFixture('m3-ultra-96gb', ['sysctl -n hw.memsize' => commandOutput(($needed - 1)."\n")], ['/api/tags' => tagsResponse([])]);
    [, $exact] = decideFixture('m3-ultra-96gb', ['sysctl -n hw.memsize' => commandOutput("{$needed}\n")], ['/api/tags' => tagsResponse([])]);

    expect($short['status'])->toBe('minimum_fit')
        ->and($exact['status'])->toBe('recommended_fit')
        ->and(fitCandidate($exact, 'gpt-oss:20b')['required'])->toMatchArray(['recommended_memory_bytes' => $needed, 'memory_headroom_bytes' => 15_000_000_000])
        ->and(InstallationHeadroom::fromConfig()->memoryRequired(13793441244))->toBe($needed);

    replayHardwareFixture('m3-ultra-96gb', ['vm_stat' => vmStatWithFreePages(intdiv($needed, 16384))], ['/api/tags' => tagsResponse([['gpt-oss:20b', GPT_OSS_20B_DIGEST, 13793441244]]), '/api/ps' => ['status' => 200, 'json' => ['models' => []]]]);

    expect(app(LocalOllama::class)->memory('gpt-oss:20b')['message'])->toStartWith('gpt-oss:20b needs 13.8 GB plus 15 GB of headroom');
});

it('downgrades a recommended fit under warning memory pressure', function () {
    [, $decision] = decideFixture('m3-ultra-96gb', ['sysctl -n kern.memorystatus_vm_pressure_level' => commandOutput("2\n")]);

    expect($decision['status'])->toBe('minimum_fit')
        ->and($decision['constraints'])->toContain('memory_pressure_warning')
        ->and($decision['install_allowed'])->toBeTrue();
});

it('refuses installs under critical memory pressure but still reports the fit', function () {
    [, $decision] = decideFixture('high-memory-pressure');

    expect($decision['status'])->toBe('minimum_fit')
        ->and($decision['install_allowed'])->toBeFalse()
        ->and($decision['install_blockers'])->toBe(['memory_pressure_critical']);
});

it('refuses a download that would leave less than 15 percent of the destination volume free', function () {
    [, $decision] = decideFixture('low-disk', http: ['/api/tags' => tagsResponse([])]);

    expect($decision['status'])->toBe('no_fit')
        ->and($decision['message'])->toBe('No supported local Ollama configuration fits this Mac.')
        ->and(fitCandidate($decision, 'gpt-oss:20b')['reasons'])->toBe(['insufficient_disk'])
        ->and(fitCandidate($decision, 'gpt-oss:20b')['required']['disk_bytes'])->toBe(13793441244 + (int) ceil(0.15 * 245107195904))
        ->and($decision['measured']['free_disk_bytes'])->toBe(2147483648);
});

it('decides the disk threshold exactly and one byte either side', function (int $offset, bool $fits) {
    $headroom = new InstallationHeadroom(11_000_000_000);
    $total = 1_000_000_000_000;
    $required = $headroom->diskRequired(13793441244, $total);
    $machine = new MachineFacts(
        snapshotSha256: str_repeat('a', 64), measuredAt: '2026-09-28T00:00:00Z', os: 'Darwin', macos: true, osVersion: '15.6',
        architecture: 'arm64', arm64Capable: true, translated: false, cpuBrand: 'Apple M2', totalMemoryBytes: 34359738368,
        memoryPressure: 'normal', metalAvailable: true, runtimeCliVersion: '0.34.4', runtimeApiVersion: '0.34.4', installedModels: [],
        destination: '/Users/test/.ollama/models', volumeMissing: false, destinationWritable: true, freeDiskBytes: $required + $offset,
        totalDiskBytes: $total, volumeName: 'Data', mountPoint: '/System/Volumes/Data',
    );

    $candidate = collect((new ModelFitRecommender)->assessAll(TrustedModelCatalogue::bundled(), $machine, $headroom))->first(fn ($c) => $c->modelId === 'gpt-oss:20b');

    expect($required)->toBe(13793441244 + 150_000_000_000)
        ->and($candidate->status->fits())->toBe($fits)
        ->and($candidate->status === ModelFitStatus::NoFit)->toBe(! $fits);
})->with([
    '1 byte short' => [-1, false],
    'exact' => [0, true],
    '1 byte over' => [1, true],
]);

it('refuses a read-only or missing destination only when something must be downloaded', function () {
    [, $removed] = decideFixture('external-volume-removed');
    $readOnly = sys_get_temp_dir().'/molly-read-only-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($readOnly);
    chmod($readOnly, 0555);

    try {
        replayHardwareFixture('m3-ultra-96gb', http: ['/api/tags' => tagsResponse([])]);
        $decision = app(DecideModelFit::class)->handle(app(InspectHardware::class)->handle($readOnly));
    } finally {
        chmod($readOnly, 0755);
        File::deleteDirectory($readOnly);
    }

    expect(fitCandidate($removed, 'gpt-oss:120b-code')['reasons'])->toBe(['destination_volume_missing'])
        ->and(fitCandidate($removed, 'gpt-oss:20b')['status'])->toBe('recommended_fit')
        ->and($decision['status'])->toBe('no_fit')
        ->and(fitCandidate($decision, 'gpt-oss:20b')['reasons'])->toBe(['destination_read_only']);
});

it('reports already installed and verified only after a readiness record for the same digest and runtime', function () {
    [, $before] = decideFixture('m2-pro-16gb-external');
    passedReadiness('gpt-oss:20b', GPT_OSS_20B_DIGEST);
    [, $after] = decideFixture('m2-pro-16gb-external');

    expect($before['status'])->toBe('minimum_fit')
        ->and($before['reasons'])->toContain('artifact_installed_unverified')
        ->and($after['status'])->toBe('already_installed')
        ->and($after['reasons'])->toBe(['artifact_already_installed', 'readiness_verified'])
        ->and($after['model_selection'])->toMatchArray(['model' => 'gpt-oss:20b', 'fit' => 'minimum_fit', 'installed' => true, 'verified' => true, 'download_bytes' => 0])
        ->and($after['inputs']['readiness_sha256'])->toBe(app(ModelRecords::class)->readinessDigest());
});

it('does not trust a readiness record for another digest or runtime version', function (string $digest, string $runtime, array $commands) {
    passedReadiness('gpt-oss:20b', $digest, $runtime);

    [, $decision] = decideFixture('m2-pro-16gb-external', $commands, $commands === [] ? [] : ['/api/version' => ['status' => 200, 'json' => ['version' => '0.33.1']]]);

    expect($decision['status'])->toBe('minimum_fit')
        ->and(fitCandidate($decision, 'gpt-oss:20b')['verified'])->toBeFalse();
})->with([
    'another digest' => [str_repeat('b', 64), '0.34.4', []],
    'another runtime version' => [GPT_OSS_20B_DIGEST, '0.33.1', []],
    'the runtime changed since the check' => [GPT_OSS_20B_DIGEST, '0.34.4', ["'/opt/homebrew/bin/ollama' --version" => commandOutput("ollama version is 0.33.1\n")]],
]);

it('keeps runtime version selection separate from model configuration', function () {
    [, $decision] = decideFixture('m3-ultra-96gb');

    expect($decision['runtime_selection'])->toBe([
        'runtime' => 'ollama',
        'version' => '0.34.4',
        'source' => 'https://github.com/ollama/ollama/tree/v0.34.4',
        'minimum_os_version' => '14.0',
        'status' => 'verified',
        'reasons' => [],
        'installed' => ['cli_version' => '0.34.4', 'api_version' => '0.34.4'],
    ])->and($decision['model_selection'])->toBe([
        'model' => 'gpt-oss:120b-code',
        'digest' => 'sha256:'.GPT_OSS_120B_CODE_DIGEST,
        'size_bytes' => 65369818623,
        'download_bytes' => 65369818941,
        'install_method' => 'derive',
        'install_from' => 'gpt-oss:120b',
        'fit' => 'recommended_fit',
        'installed' => false,
        'verified' => false,
        'runtime' => 'ollama',
    ]);
});

it('flags an installed runtime that is not the pinned version without changing the model decision', function () {
    [, $decision] = decideFixture('m3-ultra-96gb', ["'/opt/homebrew/bin/ollama' --version" => commandOutput("ollama version is 0.33.1\n")], ['/api/version' => ['status' => 200, 'json' => ['version' => '0.33.1']]]);

    expect($decision['status'])->toBe('recommended_fit')
        ->and($decision['runtime_selection'])->toMatchArray(['status' => 'version_mismatch', 'reasons' => ['runtime_version_mismatch:cli', 'runtime_version_mismatch:api']])
        ->and($decision['flags'])->toContain('runtime_version_mismatch')
        ->and($decision['install_allowed'])->toBeFalse()
        ->and($decision['install_blockers'])->toBe(['runtime_version_mismatch']);
});

it('flags an installed model whose digest is not the approved one and never treats it as installed', function () {
    passedReadiness('gpt-oss:20b', str_repeat('c', 64));
    [, $decision] = decideFixture('m2-pro-16gb-external', http: ['/api/tags' => tagsResponse([['gpt-oss:20b', str_repeat('c', 64), 13793441244]])]);

    expect($decision['flags'])->toContain('installed_digest_mismatch:gpt-oss:20b')
        ->and($decision['installed_models'][0])->toMatchArray(['name' => 'gpt-oss:20b', 'admission' => 'digest_mismatch'])
        ->and(fitCandidate($decision, 'gpt-oss:20b'))->toMatchArray(['installed' => false, 'verified' => false])
        ->and(fitCandidate($decision, 'gpt-oss:20b')['constraints'])->toContain('installed_digest_mismatch')
        ->and($decision['install_blockers'])->toContain('installed_digest_mismatch');
});

it('flags a stale catalogue and allows no download from it', function () {
    $this->travelTo('2027-04-01 12:00:00');
    [, $decision] = decideFixture('m3-ultra-96gb');

    expect($decision['status'])->toBe('recommended_fit')
        ->and($decision['inputs'])->toMatchArray(['measured_on' => '2027-04-01', 'catalogue_review_by' => '2027-03-28'])
        ->and($decision['flags'])->toContain('catalogue_stale')
        ->and($decision['install_allowed'])->toBeFalse()
        ->and($decision['install_blockers'])->toBe(['catalogue_stale']);
});

it('reports unknown when the catalogue was written for another policy or was changed', function (array $change, ?string $checksum, string $reason) {
    $document = json_decode(File::get(TrustedModelCatalogue::BUNDLED), true);
    $directory = sys_get_temp_dir().'/molly-fit-catalogue-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($directory);
    $json = json_encode(array_replace($document, $change), JSON_THROW_ON_ERROR);
    File::put($directory.'/catalogue.v1.json', $json);
    File::put($directory.'/catalogue.v1.sha256', $checksum ?? hash('sha256', $json));
    app()->instance(DecideModelFit::class, app()->make(DecideModelFit::class, ['cataloguePath' => $directory.'/catalogue.v1.json']));

    [, $decision] = decideFixture('m3-ultra-96gb');

    expect($decision['status'])->toBe('unknown')
        ->and($decision['reasons'])->toBe([$reason])
        ->and($decision['flags'])->toBe(['catalogue_unusable'])
        ->and($decision['install_allowed'])->toBeFalse()
        ->and($decision['model_selection'])->toBeNull();
})->with([
    'stale policy' => [['policy_version' => '0.9.0'], null, 'catalogue_policy_stale'],
    'changed file' => [['review_by' => '2099-01-01'], str_repeat('0', 64), 'catalogue_integrity_failed'],
]);

it('lists unapproved installed models without selecting, replacing, or activating them', function () {
    config(['molly.model' => 'qwen2.5-coder:7b']);
    [, $decision] = decideFixture('m3-ultra-96gb', http: ['/api/tags' => tagsResponse([
        ['custom:40b', str_repeat('d', 64), 40000000000],
        ['gpt-oss:120b', GPT_OSS_120B_DIGEST, 65369818941],
        ['qwen2.5-coder:7b', 'dae161e27b0e90dd1856c8bb3209201fd6736d8eb66298e75ed87571486f4364', 4683087561],
    ])]);

    expect(array_column($decision['installed_models'], 'admission', 'name'))->toBe([
        'custom:40b' => 'not_catalogued',
        'gpt-oss:120b' => 'build_input',
        'qwen2.5-coder:7b' => 'rejected',
    ])->and(collect($decision['installed_models'])->firstWhere('name', 'qwen2.5-coder:7b')['reasons'])->toBe(['origin_not_us_developed', 'developer_organization_not_us'])
        ->and(collect($decision['installed_models'])->firstWhere('name', 'gpt-oss:120b')['reasons'])->toBe(['base_for:gpt-oss:120b-code'])
        ->and($decision['configured_model'])->toMatchArray(['name' => 'qwen2.5-coder:7b', 'installed' => true, 'admission' => 'rejected'])
        ->and($decision['flags'])->toContain('configured_model_not_approved:qwen2.5-coder:7b')
        ->and($decision['model_selection']['model'])->toBe('gpt-oss:120b-code')
        ->and(fitCandidate($decision, 'gpt-oss:120b-code')['download_bytes'])->toBe(0)
        ->and(config('molly.model'))->toBe('qwen2.5-coder:7b');
});

it('reaches the same decision from the same snapshot every time', function () {
    $fixture = replayHardwareFixture('m3-ultra-96gb');
    $snapshot = app(InspectHardware::class)->handle($fixture['destination']);

    $first = app(DecideModelFit::class)->handle($snapshot);
    $second = app(DecideModelFit::class)->handle(json_decode(json_encode($snapshot), true));

    expect($second)->toBe($first);
});

it('rejects a snapshot whose digest does not match its facts', function () {
    $fixture = replayHardwareFixture('m3-ultra-96gb');
    $snapshot = app(InspectHardware::class)->handle($fixture['destination']);
    $snapshot['facts']['memory']['total_bytes']['value'] = 999;

    expect(fn () => app(DecideModelFit::class)->handle($snapshot))->toThrow(InvalidArgumentException::class, 'SNAPSHOT_INVALID: snapshot_sha256 does not match the facts in the snapshot.');
});

describe('molly:preflight --json', function () {
    it('adds the fit decision keyed to the snapshot digest', function () {
        $fixture = replayHardwareFixture('m3-ultra-96gb');

        [$exit, $document] = mollyJson('molly:preflight', ['--destination' => $fixture['destination']]);

        expect($exit)->toBe(0)
            ->and($document['decision']['snapshot_sha256'])->toBe($document['snapshot_sha256'])
            ->and($document['snapshot_sha256'])->toBe(HardwareSnapshot::hash($document['facts']))
            ->and($document['decision']['status'])->toBe('recommended_fit');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/api/pull') || str_contains($request->url(), '/api/create'));
    });

    it('reaches the identical decision from a saved snapshot, as another reader of the same file would', function () {
        $fixture = replayHardwareFixture('m2-pro-16gb-external');
        [, $measured] = mollyJson('molly:preflight', ['--destination' => $fixture['destination']]);
        $saved = $this->home.'/snapshot.json';
        File::ensureDirectoryExists($this->home);
        File::put($saved, json_encode($measured, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));

        [$exit, $replayed] = mollyJson('molly:preflight', ['--snapshot' => $saved]);

        expect($exit)->toBe(0)
            ->and($replayed['decision'])->toBe($measured['decision'])
            ->and($replayed['decision']['decision_sha256'])->toBe($measured['decision']['decision_sha256'])
            ->and(array_diff_key($replayed, ['decision' => true]))->toBe(array_diff_key($measured, ['decision' => true]));
    });

    it('refuses a saved snapshot whose facts were changed', function () {
        $fixture = replayHardwareFixture('m2-pro-16gb-external');
        [, $measured] = mollyJson('molly:preflight', ['--destination' => $fixture['destination']]);
        $measured['facts']['memory']['total_bytes']['value'] = 206158430208;
        $saved = $this->home.'/changed.json';
        File::ensureDirectoryExists($this->home);
        File::put($saved, json_encode($measured, JSON_THROW_ON_ERROR));

        [$exit, $document] = mollyJson('molly:preflight', ['--snapshot' => $saved]);

        expect($exit)->toBe(1)
            ->and($document['error'])->toBe('snapshot_invalid')
            ->and($document['message'])->toBe('SNAPSHOT_INVALID: snapshot_sha256 does not match the facts in the snapshot.');
    });
});
