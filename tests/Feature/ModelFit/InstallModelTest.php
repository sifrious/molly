<?php

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\DecideModelFit;
use Sifrious\Molly\Actions\InspectHardware;
use Sifrious\Molly\Actions\InstallModel;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\ReadinessCheck;
use Sifrious\Molly\Agents\TarpitReviewer;
use Sifrious\Molly\ModelFit\ModelRecords;
use Sifrious\Molly\Settings\SettingsStore;
use Symfony\Component\Process\Process as SymfonyProcess;

/*
 * The installer talks only to the loopback Ollama API. Each test replays a simulated host,
 * then fakes that API with fakeOllamaApi(): a pull or create that succeeds adds the model
 * to /api/tags, and every other request fails the test, so no test can download anything.
 * Readiness answers come from Laravel AI agent fakes.
 */

beforeEach(function () {
    $this->home = sys_get_temp_dir().'/molly-install-home-'.bin2hex(random_bytes(6));
    app()->instance(SettingsStore::class, new SettingsStore($this->home));
    config(['molly.agent' => 'ollama', 'molly.model' => 'local-test-model']);
});

afterEach(function () {
    File::deleteDirectory($this->home);
});

/** Replay a host with plenty of free memory, so the readiness load check passes. */
function installHost(string $fixture, array $commands = []): array
{
    return replayHardwareFixture($fixture, ['vm_stat' => commandOutput("Mach Virtual Memory Statistics: (page size of 16384 bytes)\nPages free: 6000000.\nPages active: 1000.\nPages inactive: 0.\nPages speculative: 0.\nPages wired down: 1000.\nPages purgeable: 0.\nPages occupied by compressor: 0.\n")] + $commands, fakeHttp: false);
}

/**
 * A stateful fake of the loopback Ollama API. `$state->pull[name]` sets how a pull of that
 * model behaves: success (default), interrupt, wrong-digest, error:<Ollama error text>, or
 * refused. `$state->onPull` runs before a pull answers.
 *
 * @param  list<string>  $installed  approved or base model names that are already installed
 * @param  list<array<string, mixed>>  $extra  other /api/tags entries
 */
function fakeOllamaApi(array $installed = [], array $extra = []): stdClass
{
    $known = ['gpt-oss:20b' => [GPT_OSS_20B_DIGEST, 13793441244], 'gpt-oss:120b' => [GPT_OSS_120B_DIGEST, 65369818941], 'gpt-oss:120b-code' => [GPT_OSS_120B_CODE_DIGEST, 65369818623]];
    $entry = fn (string $name, ?string $digest = null): array => ['name' => $name, 'model' => $name, 'digest' => $digest ?? $known[$name][0], 'size' => $known[$name][1]];
    $state = (object) ['models' => [], 'version' => '0.34.4', 'pull' => [], 'pulls' => [], 'creates' => [], 'onPull' => null];
    foreach ($installed as $name) {
        $state->models[$name] = $entry($name);
    }
    foreach ($extra as $model) {
        $state->models[$model['name']] = $model;
    }
    $stream = fn (array $lines) => Http::response(implode("\n", array_map(fn (array $line): string => json_encode($line), $lines))."\n");

    Http::swap(new HttpFactory(app('events')));
    Http::preventStrayRequests();
    Http::fake([
        'localhost:11434/api/version' => fn () => Http::response(['version' => $state->version]),
        'localhost:11434/api/tags' => fn () => Http::response(['models' => array_values($state->models)]),
        'localhost:11434/api/ps' => fn () => Http::response(['models' => []]),
        'localhost:11434/api/pull' => function (Request $request) use ($state, $entry, $stream, $known) {
            $name = $request['model'];
            $state->pulls[] = $name;
            if ($state->onPull !== null) {
                ($state->onPull)($name);
            }
            $size = $known[$name][1];
            $started = [['status' => 'pulling manifest'], ['status' => 'pulling weights', 'digest' => 'sha256:weights', 'total' => $size, 'completed' => intdiv($size, 2)]];
            $finished = [['status' => 'pulling weights', 'digest' => 'sha256:weights', 'total' => $size, 'completed' => $size], ['status' => 'verifying sha256 digest'], ['status' => 'writing manifest'], ['status' => 'success']];
            $behavior = $state->pull[$name] ?? 'success';

            return match (true) {
                $behavior === 'success' => (function () use ($state, $entry, $name, $stream, $started, $finished) {
                    $state->models[$name] = $entry($name);

                    return $stream([...$started, ...$finished]);
                })(),
                $behavior === 'wrong-digest' => (function () use ($state, $entry, $name, $stream, $started, $finished) {
                    $state->models[$name] = $entry($name, str_repeat('e', 64));

                    return $stream([...$started, ...$finished]);
                })(),
                $behavior === 'interrupt' => $stream($started),
                $behavior === 'refused' => Http::failedConnection(),
                default => $stream([...$started, ['error' => substr($behavior, 6)]]),
            };
        },
        'localhost:11434/api/create' => function (Request $request) use ($state, $entry) {
            $state->creates[] = $request->data();
            $state->models[$request['model']] = $entry($request['model']);

            return Http::response(['status' => 'success']);
        },
    ]);

    return $state;
}

function readyModel(): void
{
    ReadinessCheck::fake([['answer' => 42]]);
    ChangeWriter::fake([['summary' => 'Add Cart::total().', 'files' => [['path' => 'app/Cart.php', 'content' => "<?php\n\nnamespace App;\n\nclass Cart\n{\n    public function __construct(private array \$lines) {}\n\n    public function total(): int\n    {\n        return array_sum(array_column(\$this->lines, 'price'));\n    }\n}\n"]]]]);
    TarpitReviewer::fake([['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'Nothing to report.']), 'findings' => []]]);
}

/** @return array{0: int, 1: array<string, mixed>} */
function installModel(array $parameters): array
{
    return mollyJson('molly:install-model', $parameters);
}

function downloadRequested(): bool
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/api/pull') || str_contains($request->url(), '/api/create'))->isNotEmpty();
}

function onlyLoopbackRequests(): bool
{
    return Http::recorded()->every(fn (array $pair): bool => str_starts_with($pair[0]->url(), 'http://localhost:11434/'));
}

it('installs the selected model after authorization, verifies the runtime and digest, and proves it ready', function () {
    $fixture = installHost('m2-pro-16gb-external');
    $api = fakeOllamaApi();
    readyModel();

    [$exit, $result] = installModel(['--destination' => $fixture['destination'], '--approve' => true]);

    expect($exit)->toBe(0)
        ->and($result)->toMatchArray(['status' => 'ready', 'code' => null, 'model' => 'gpt-oss:20b', 'resumed' => false])
        ->and($result['message'])->toBe('gpt-oss:20b is installed with the approved digest on Ollama 0.34.4 and passed the readiness check.')
        ->and($api->pulls)->toBe(['gpt-oss:20b'])
        ->and($api->creates)->toBe([])
        ->and($result['verification'])->toBe([
            'runtime' => ['cli_version' => '0.34.4', 'api_version' => '0.34.4', 'expected' => '0.34.4'],
            'digest' => ['expected' => 'sha256:'.GPT_OSS_20B_DIGEST, 'installed' => 'sha256:'.GPT_OSS_20B_DIGEST],
        ])
        ->and($result['readiness']['passed'])->toBeTrue()
        ->and(array_column($result['events'], 'step'))->toBe(['recheck', 'download', 'verify', 'readiness'])
        ->and($result['next'])->toBe(['php artisan molly:setup --agent=ollama --model=gpt-oss:20b', 'php artisan config:clear', 'php artisan molly:doctor'])
        ->and(app(ModelRecords::class)->journal('gpt-oss:20b')['state'])->toBe('ready')
        ->and(config('molly.model'))->toBe('local-test-model')
        ->and(onlyLoopbackRequests())->toBeTrue();

    $decision = app(DecideModelFit::class)->handle(app(InspectHardware::class)->handle($fixture['destination']));
    expect($decision['status'])->toBe('already_installed')
        ->and($decision['model_selection']['model'])->toBe('gpt-oss:20b');
});

it('derives gpt-oss:120b-code from its pinned base with the approved parameters', function () {
    $fixture = installHost('m3-ultra-96gb');
    $api = fakeOllamaApi();
    readyModel();

    [$exit, $result] = installModel(['--destination' => $fixture['destination'], '--approve' => true]);

    expect($exit)->toBe(0)
        ->and($result['model'])->toBe('gpt-oss:120b-code')
        ->and($result['plan']['download'])->toMatchArray(['bytes' => 65369818941, 'model' => 'gpt-oss:120b'])
        ->and($api->pulls)->toBe(['gpt-oss:120b'])
        ->and($api->creates)->toBe([['model' => 'gpt-oss:120b-code', 'from' => 'gpt-oss:120b', 'parameters' => ['num_ctx' => 65536, 'temperature' => 1], 'stream' => false]])
        ->and($result['verification']['base'])->toBe(['model' => 'gpt-oss:120b', 'expected' => 'sha256:'.GPT_OSS_120B_DIGEST, 'installed' => 'sha256:'.GPT_OSS_120B_DIGEST])
        ->and($result['verification']['digest']['installed'])->toBe('sha256:'.GPT_OSS_120B_CODE_DIGEST)
        ->and(array_keys($api->models))->toBe(['gpt-oss:120b', 'gpt-oss:120b-code'])
        ->and(array_column($result['events'], 'step'))->toBe(['recheck', 'download', 'derive', 'verify', 'readiness']);
});

it('needs no authorization when nothing has to be downloaded', function (array $installed, array $pulls, int $creates) {
    $fixture = installHost('m3-ultra-96gb');
    $api = fakeOllamaApi($installed);
    readyModel();

    [$exit, $result] = installModel(['--destination' => $fixture['destination']]);

    expect($exit)->toBe(0)
        ->and($result['status'])->toBe('ready')
        ->and($result['plan']['download']['bytes'])->toBe(0)
        ->and($api->pulls)->toBe($pulls)
        ->and($api->creates)->toHaveCount($creates);
})->with([
    'the base model is installed' => [['gpt-oss:120b'], [], 1],
    'the approved model is installed' => [['gpt-oss:120b-code'], [], 0],
]);

it('lets the person choose any approved model that fits', function () {
    $fixture = installHost('m3-ultra-96gb');
    $api = fakeOllamaApi();
    readyModel();

    [$exit, $result] = installModel(['model' => 'gpt-oss:20b', '--destination' => $fixture['destination'], '--approve' => true]);

    expect($exit)->toBe(0)
        ->and($result['model'])->toBe('gpt-oss:20b')
        ->and($result['decision']['model_selection']['model'])->toBe('gpt-oss:120b-code')
        ->and($api->pulls)->toBe(['gpt-oss:20b']);
});

describe('authorization', function () {
    it('asks for authorization before a download and shows the size and the destination volume', function () {
        $fixture = installHost('m2-pro-16gb-external');
        fakeOllamaApi();

        [$exit, $result] = installModel(['--destination' => $fixture['destination']]);

        expect($exit)->toBe(1)
            ->and($result)->toMatchArray(['status' => 'authorization_required', 'code' => 'DOWNLOAD_AUTHORIZATION_REQUIRED', 'rerun' => 'php artisan molly:install-model gpt-oss:20b --destination='.$fixture['destination'].' --approve --json'])
            ->and($result['message'])->toBe('Download 13.8 GB for gpt-oss:20b to '.$fixture['destination'].' on the ModelDisk volume mounted at /Volumes/ModelDisk, which has 1538.3 GB free? Molly measures memory, disk, and the runtime again right before it starts.')
            ->and($result['download'])->toMatchArray(['bytes' => 13793441244, 'volume_name' => 'ModelDisk', 'mount_point' => '/Volumes/ModelDisk'])
            ->and(downloadRequested())->toBeFalse();
    });

    it('prints a command that plans the same download when run exactly as printed', function () {
        $fixture = installHost('m2-pro-16gb-external');
        $api = fakeOllamaApi();
        readyModel();
        $destination = $fixture['destination'].'/Ollama models';
        File::ensureDirectoryExists($destination);

        [$exit, $refused] = installModel(['--destination' => $destination]);

        expect($exit)->toBe(1)
            ->and($refused['rerun'])->toBe('php artisan molly:install-model gpt-oss:20b --destination='.escapeshellarg($destination).' --approve --json')
            ->and($refused['download']['destination'])->toBe($destination)
            ->and($destination)->not->toBe(getenv('HOME').'/.ollama/models')
            ->and($api->pulls)->toBe([]);

        $exit = Artisan::call(Str::after($refused['rerun'], 'php artisan '));
        $installed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($exit)->toBe(0)
            ->and($installed['plan']['download'])->toBe($refused['download'])
            ->and($installed['recheck']['snapshot_sha256'])->toBe($refused['snapshot_sha256'])
            ->and($installed['recheck']['decision_sha256'])->toBe($refused['decision']['decision_sha256'])
            ->and($api->pulls)->toBe(['gpt-oss:20b']);
    });

    it('keeps the models directory in the command it prints without a terminal', function () {
        $fixture = installHost('m2-pro-16gb-external');
        fakeOllamaApi();

        $exit = Artisan::call('molly:install-model', ['--destination' => $fixture['destination'], '--no-interaction' => true]);

        expect($exit)->toBe(1)
            ->and(Artisan::output())->toContain('Run: php artisan molly:install-model gpt-oss:20b --destination='.$fixture['destination'].' --approve')
            ->not->toContain('--json')
            ->and(downloadRequested())->toBeFalse();
    });

    it('downloads nothing when the person declines', function () {
        $fixture = installHost('m2-pro-16gb-external');
        fakeOllamaApi();

        $this->artisan('molly:install-model', ['--destination' => $fixture['destination']])
            ->expectsConfirmation('Download 13.8 GB for gpt-oss:20b?', 'no')
            ->expectsOutputToContain('DOWNLOAD_NOT_AUTHORIZED: Molly downloaded nothing.')
            ->assertExitCode(1);

        expect(downloadRequested())->toBeFalse();
    });

    it('installs after the person confirms in the terminal', function () {
        $fixture = installHost('m2-pro-16gb-external');
        $api = fakeOllamaApi();
        readyModel();

        $this->artisan('molly:install-model', ['--destination' => $fixture['destination']])
            ->expectsConfirmation('Download 13.8 GB for gpt-oss:20b?', 'yes')
            ->expectsOutputToContain('gpt-oss:20b is installed with the approved digest on Ollama 0.34.4 and passed the readiness check.')
            ->assertExitCode(0);

        expect($api->pulls)->toBe(['gpt-oss:20b']);
    });

    it('rechecks resources immediately before installing and refuses when they changed', function () {
        $calls = 0;
        $fixture = installHost('m2-pro-16gb-external', ['df -kP *' => function () use (&$calls) {
            $calls++;
            $available = $calls === 1 ? 1502233100 : 2097152;

            return Process::result("Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/disk6s1 1953514584 451281484 {$available} 23% /Volumes/ModelDisk\n");
        }]);
        fakeOllamaApi();

        [$exit, $result] = installModel(['--destination' => $fixture['destination'], '--approve' => true]);

        expect($exit)->toBe(1)
            ->and($calls)->toBe(2)
            ->and($result)->toMatchArray(['status' => 'refused', 'code' => 'DISK_INSUFFICIENT'])
            ->and($result['recheck']['status'])->toBe('refused')
            ->and($result['events'][0]['step'])->toBe('recheck')
            ->and($result['events'][0]['detail'])->toContain('immediately before installing')
            ->and(downloadRequested())->toBeFalse();
    });

    it('refuses when the download grew beyond what was authorized', function () {
        $fixture = installHost('m3-ultra-96gb');
        fakeOllamaApi();

        $result = app(InstallModel::class)->install('gpt-oss:120b-code', $fixture['destination'], authorizedBytes: 13793441244);

        expect($result['code'])->toBe('DOWNLOAD_AUTHORIZATION_REQUIRED')
            ->and($result['message'])->toBe('Installing gpt-oss:120b-code downloads 65.4 GB, and 13.8 GB was authorized. Molly downloaded nothing. Authorize the download shown and run the command again.')
            ->and(downloadRequested())->toBeFalse();
    });
});

it('downloads nothing and changes no model when the model does not fit, is unknown, or is not approved', function (string $fixture, array $parameters, Closure $arrange, string $code, string $message) {
    $host = installHost($fixture);
    $api = fakeOllamaApi();
    $arrange($api, $this);

    [$exit, $result] = installModel($parameters + ['--destination' => $host['destination'], '--approve' => true]);

    expect($exit)->toBe(1)
        ->and($result['status'])->toBe('refused')
        ->and($result['code'])->toBe($code)
        ->and($result['message'])->toStartWith($message)
        ->and(downloadRequested())->toBeFalse()
        ->and($api->pulls)->toBe([])
        ->and(onlyLoopbackRequests())->toBeTrue()
        ->and(config('molly.agent'))->toBe('ollama')
        ->and(config('molly.model'))->toBe('local-test-model')
        ->and(app(ModelRecords::class)->journal($result['model'] ?? 'gpt-oss:20b'))->toBeNull();
})->with([
    'no fit' => ['macbook-air-8gb', [], fn () => null, 'NO_FIT', 'No supported local Ollama configuration fits this Mac. Reasons: insufficient_memory, insufficient_disk.'],
    'unknown compatibility' => ['probes-unknown', [], fn () => null, 'MODEL_FIT_UNKNOWN', 'Molly could not decide whether a local Ollama configuration fits this Mac'],
    'Intel Mac' => ['intel-mac-x86_64', [], fn () => null, 'PLATFORM_UNSUPPORTED', 'Molly\'s approved local Ollama configuration needs an Apple silicon Mac with macOS 14.0 or later.'],
    'a model that does not fit' => ['m2-pro-16gb-external', ['model' => 'gpt-oss:120b-code'], fn () => null, 'MODEL_DOES_NOT_FIT', 'gpt-oss:120b-code does not fit this Mac (insufficient_memory).'],
    'an unapproved base model' => ['m3-ultra-96gb', ['model' => 'gpt-oss:120b'], fn () => null, 'MODEL_NOT_APPROVED', 'gpt-oss:120b is not an approved model (model_not_catalogued). Molly installs only gpt-oss:20b, gpt-oss:120b-code.'],
    'a model outside the origin policy' => ['m3-ultra-96gb', ['model' => 'qwen2.5-coder:7b'], fn () => null, 'MODEL_NOT_APPROVED', 'qwen2.5-coder:7b is not an approved model (origin_not_us_developed, developer_organization_not_us).'],
    'a stale catalogue' => ['m3-ultra-96gb', [], fn ($api, $test) => $test->travelTo('2027-04-01 12:00:00'), 'CATALOGUE_STALE', 'Molly\'s approved model catalogue was due for review on 2027-03-28.'],
    'another runtime version' => ['m3-ultra-96gb', [], fn ($api) => $api->version = '0.33.1', 'RUNTIME_VERSION_MISMATCH', 'Ollama reports CLI 0.34.4 and API 0.33.1, and the catalogue pins 0.34.4.'],
    'critical memory pressure' => ['high-memory-pressure', [], fn () => null, 'MEMORY_PRESSURE_CRITICAL', 'macOS reports critical memory pressure.'],
    'another model under the approved name' => ['m2-pro-16gb-external', [], fn ($api) => $api->models['gpt-oss:20b'] = ['name' => 'gpt-oss:20b', 'digest' => str_repeat('c', 64), 'size' => 1], 'INSTALLED_DIGEST_CONFLICT', 'gpt-oss:20b is already installed with a digest other than the approved'],
    'another model under the base name' => ['m3-ultra-96gb', [], fn ($api) => $api->models['gpt-oss:120b'] = ['name' => 'gpt-oss:120b', 'digest' => str_repeat('c', 64), 'size' => 1], 'BASE_DIGEST_CONFLICT', 'gpt-oss:120b is installed with a digest other than the pinned'],
]);

describe('faults', function () {
    it('reports an interrupted download and resumes it on the next run', function () {
        $fixture = installHost('m2-pro-16gb-external');
        $api = fakeOllamaApi();
        $api->pull['gpt-oss:20b'] = 'interrupt';

        [$exit, $first] = installModel(['--destination' => $fixture['destination'], '--approve' => true]);

        expect($exit)->toBe(1)
            ->and($first)->toMatchArray(['status' => 'failed', 'code' => 'DOWNLOAD_INTERRUPTED', 'completed_bytes' => 6896720622])
            ->and($first['message'])->toBe('The download of gpt-oss:20b stopped after 6.9 GB without Ollama reporting success. Ollama keeps the part it downloaded; run this command again to resume.')
            ->and(app(ModelRecords::class)->journal('gpt-oss:20b'))->toMatchArray(['state' => 'interrupted', 'completed_bytes' => 6896720622, 'code' => 'DOWNLOAD_INTERRUPTED']);

        $api->pull['gpt-oss:20b'] = 'success';
        readyModel();
        [$exit, $second] = installModel(['--destination' => $fixture['destination'], '--approve' => true]);

        expect($exit)->toBe(0)
            ->and($second)->toMatchArray(['status' => 'ready', 'resumed' => true])
            ->and($second['events'][1])->toMatchArray(['step' => 'resume', 'detail' => 'A previous install of gpt-oss:20b stopped in state interrupted after 6.9 GB. Ollama continues from the part it kept.'])
            ->and($api->pulls)->toBe(['gpt-oss:20b', 'gpt-oss:20b']);
    });

    it('reports Ollama partial files for the approved weights in the plan', function () {
        $fixture = installHost('m2-pro-16gb-external');
        fakeOllamaApi();
        File::ensureDirectoryExists($fixture['destination'].'/blobs');
        File::put($fixture['destination'].'/blobs/sha256-e7b273f9636059a689e3ddcab3716e4f65abe0143ac978e46673ad0e52d09efb-partial', 'part');

        $plan = app(InstallModel::class)->plan(destination: $fixture['destination']);

        expect($plan['download']['partial_download_present'])->toBeTrue();
    });

    it('turns each download failure into a coded state', function (string $behavior, string $code, string $message) {
        $fixture = installHost('m2-pro-16gb-external');
        $api = fakeOllamaApi();
        $api->pull['gpt-oss:20b'] = $behavior;

        [$exit, $result] = installModel(['--destination' => $fixture['destination'], '--approve' => true]);

        expect($exit)->toBe(1)
            ->and($result['status'])->toBe('failed')
            ->and($result['code'])->toBe($code)
            ->and($result['message'])->toStartWith($message)
            ->and($result)->not->toHaveKey('readiness')
            ->and(app(ModelRecords::class)->readiness())->toBe([])
            ->and(app(ModelRecords::class)->journal('gpt-oss:20b')['state'])->toBe('failed');
    })->with([
        'corrupt part' => ['error:digest mismatch, file must be downloaded again: expected sha256:e7b273f9, got sha256:0000', 'DIGEST_MISMATCH', 'Ollama rejected a downloaded part of gpt-oss:20b whose SHA-256 did not match, and kept none of it.'],
        'wrong manifest' => ['wrong-digest', 'DIGEST_MISMATCH', 'Ollama lists gpt-oss:20b with digest sha256:'.str_repeat('e', 64).', not the approved sha256:'.GPT_OSS_20B_DIGEST.'. Molly did not mark it ready and will not use it.'],
        'offline' => ['error:pull model manifest: Get "https://registry.ollama.ai/v2/library/gpt-oss/manifests/20b": dial tcp: lookup registry.ollama.ai: no such host', 'DOWNLOAD_OFFLINE', 'Ollama could not reach its registry to download gpt-oss:20b.'],
        'read-only destination' => ['error:write /Volumes/ModelDisk/ollama/models/blobs/sha256-e7b273f9-partial: read-only file system', 'DESTINATION_READ_ONLY', 'Ollama could not write gpt-oss:20b to its models directory'],
        'full disk' => ['error:write /Volumes/ModelDisk/ollama/models/blobs/sha256-e7b273f9-partial: no space left on device', 'DISK_FULL', 'The models volume ran out of space after 6.9 GB.'],
        'Ollama stopped' => ['refused', 'RUNTIME_UNREACHABLE', 'Molly could not reach Ollama at http://localhost:11434 to start the download'],
        'other error' => ['error:unexpected EOF', 'DOWNLOAD_FAILED', 'Ollama did not download gpt-oss:20b. Ollama said: unexpected EOF'],
    ]);

    it('refuses a read-only destination before downloading', function () {
        $fixture = installHost('m2-pro-16gb-external');
        fakeOllamaApi();
        chmod($fixture['destination'], 0555);

        try {
            [$exit, $result] = installModel(['--destination' => $fixture['destination'], '--approve' => true]);
        } finally {
            chmod($fixture['destination'], 0755);
        }

        expect($exit)->toBe(1)
            ->and($result['code'])->toBe('DESTINATION_READ_ONLY')
            ->and(downloadRequested())->toBeFalse();
    });

    it('refuses a destination on a volume that is not mounted before downloading', function () {
        $fixture = installHost('external-volume-removed');
        fakeOllamaApi();

        [$exit, $result] = installModel(['model' => 'gpt-oss:120b-code', '--destination' => $fixture['destination'], '--approve' => true]);

        expect($exit)->toBe(1)
            ->and($result['code'])->toBe('DESTINATION_VOLUME_MISSING')
            ->and($result['message'])->toBe('The volume for /Volumes/MollyFixtureRemovedDisk/ollama/models is not mounted. Molly downloaded nothing.')
            ->and(downloadRequested())->toBeFalse();
    });

    it('says the destination was removed when it disappears during the download', function () {
        $fixture = installHost('m2-pro-16gb-external');
        $api = fakeOllamaApi();
        $api->pull['gpt-oss:20b'] = 'error:open '.$fixture['destination'].'/blobs/sha256-e7b273f9-partial: no such file or directory';
        $api->onPull = fn () => File::deleteDirectory($fixture['destination']);

        [$exit, $result] = installModel(['--destination' => $fixture['destination'], '--approve' => true]);

        expect($exit)->toBe(1)
            ->and($result['code'])->toBe('DESTINATION_REMOVED')
            ->and($result['message'])->toBe('The models directory '.$fixture['destination'].' disappeared during the download. Molly installed nothing. Reconnect the volume, then run this command again.');
    });

    it('refuses a second install while one holds the lock', function () {
        $fixture = installHost('m2-pro-16gb-external');
        fakeOllamaApi();
        $held = app(ModelRecords::class)->lock();

        try {
            [$exit, $result] = installModel(['--destination' => $fixture['destination'], '--approve' => true]);
        } finally {
            fclose($held);
        }

        expect($held)->not->toBeNull()
            ->and($exit)->toBe(1)
            ->and($result['code'])->toBe('INSTALL_IN_PROGRESS')
            ->and(downloadRequested())->toBeFalse();
    });

    it('verifies the runtime version after the download with the ollama binary on PATH', function () {
        $bin = sys_get_temp_dir().'/molly-fake-ollama-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($bin);
        File::put($bin.'/version', '0.34.4');
        File::put($bin.'/ollama', "#!/bin/sh\nif [ \"\$1\" = \"--version\" ]; then echo \"ollama version is \$(cat '{$bin}/version')\"; exit 0; fi\necho 'this fake runs only --version' >&2\nexit 2\n");
        chmod($bin.'/ollama', 0755);
        $real = fn (string $command): Closure => function () use ($command, $bin) {
            $process = SymfonyProcess::fromShellCommandline($command, null, ['PATH' => $bin.':/usr/bin:/bin']);
            $process->run();

            return Process::result($process->getOutput(), $process->getErrorOutput(), (int) $process->getExitCode());
        };
        $fixture = installHost('m2-pro-16gb-external', ['command -v ollama' => $real('command -v ollama'), "'{$bin}/ollama' --version" => $real("'{$bin}/ollama' --version")]);
        $api = fakeOllamaApi();
        $api->onPull = function () use ($bin, $api) {
            File::put($bin.'/version', '0.35.0');
            $api->version = '0.35.0';
        };

        try {
            [$exit, $result] = installModel(['--destination' => $fixture['destination'], '--approve' => true]);
        } finally {
            File::deleteDirectory($bin);
        }

        expect($exit)->toBe(1)
            ->and($result['decision']['runtime_selection']['installed'])->toBe(['cli_version' => '0.34.4', 'api_version' => '0.34.4'])
            ->and($result['code'])->toBe('RUNTIME_VERSION_MISMATCH')
            ->and($result['verification']['runtime'])->toBe(['cli_version' => '0.35.0', 'api_version' => '0.35.0', 'expected' => '0.34.4'])
            ->and($result['message'])->toStartWith('After the download, Ollama reports CLI 0.35.0 and API 0.35.0, and the catalogue pins 0.34.4.')
            ->and(app(ModelRecords::class)->readiness())->toBe([]);
    });
});

it('keeps an installed model that fails the readiness check out of already_installed', function () {
    $fixture = installHost('m2-pro-16gb-external');
    fakeOllamaApi(['gpt-oss:20b']);
    ReadinessCheck::fake([['answer' => 42]]);
    ChangeWriter::fake([['summary' => 'Hello.', 'files' => [['path' => 'app/Cart.php', 'content' => "<?php\n\nnamespace App;\n\nclass Cart\n{\n}\n"]]]]);

    [$exit, $result] = installModel(['--destination' => $fixture['destination']]);
    $decision = app(DecideModelFit::class)->handle(app(InspectHardware::class)->handle($fixture['destination']));

    expect($exit)->toBe(1)
        ->and($result)->toMatchArray(['status' => 'installed_not_ready', 'code' => 'READINESS_FAILED'])
        ->and($result['message'])->toBe('gpt-oss:20b is installed with the approved digest, but the readiness check failed: READINESS_TASK_INVALID: The proposed app/Cart.php does not define Cart::total(). Molly does not call it ready. Run this command again to repeat the check.')
        ->and($result['readiness']['checks']['bounded_inference']['passed'])->toBeTrue()
        ->and(app(ModelRecords::class)->journal('gpt-oss:20b')['state'])->toBe('not_ready')
        ->and($decision['status'])->toBe('minimum_fit')
        ->and($decision['reasons'])->toContain('artifact_installed_unverified');
});

it('prints the steps it took when it installs from the terminal', function () {
    $fixture = installHost('m3-ultra-96gb');
    fakeOllamaApi(['gpt-oss:120b-code']);
    readyModel();

    $exit = Artisan::call('molly:install-model', ['--destination' => $fixture['destination'], '--no-interaction' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('recheck: Molly measured memory, disk, and the runtime again immediately before installing')
        ->toContain('readiness: The bounded inference and the Molly task both passed.')
        ->toContain('php artisan molly:setup --agent=ollama --model=gpt-oss:120b-code');
});
