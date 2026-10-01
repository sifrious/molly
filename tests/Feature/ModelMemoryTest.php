<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CheckEnvironment;
use Sifrious\Molly\Actions\CreateTaskFromStory;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Agents\LocalOllama;
use Sifrious\Molly\Models\Task;

/*
 * Before Ollama loads a model, LocalOllama compares the model's size plus
 * molly.memory.headroom_gb with the available memory HardwareProbe measures for
 * molly:preflight. These tests replay the M07.11 acceptance host through
 * Process::fake and Http::fake: 44.1 GB available, gpt-oss:120b-code loaded, and
 * gpt-oss:120b (65.4 GB) installed but not loaded. No test reaches a real Ollama.
 */

function memoryHostVmStat(): string
{
    return <<<'TEXT'
Mach Virtual Memory Statistics: (page size of 16384 bytes)
Pages free:                               88559.
Pages active:                           2562437.
Pages inactive:                         2601513.
Pages speculative:                          681.
Pages throttled:                              0.
Pages wired down:                        295105.
Pages purgeable:                          20154.
Pages stored in compressor:             1270328.
Pages occupied by compressor:            689690.
TEXT;
}

/** @return list<array<string, mixed>> */
function memoryHostInstalledModels(): array
{
    return [
        ['name' => 'gpt-oss:120b', 'size' => 65369818941, 'digest' => 'a951a23b46a1f6093dafee2ea481d634b4e31ac720a8a16f3f91e04f5a40ecd9'],
        ['name' => 'gpt-oss:120b-code', 'size' => 65369818623, 'digest' => '4dda6ee4a98ead297f4cdeae389ecdc1963ed4cad90b10c8a11e1cc2a7b0fcaf'],
        ['name' => 'gpt-oss:20b', 'size' => 13793441244, 'digest' => '17052f91a42e97930aa6e28a6c6c06a983e6a58dbb00434885a0cf5313e376f7'],
        ['name' => 'custom:40b', 'size' => 40000000000, 'digest' => 'simulated'],
    ];
}

/** @return list<array<string, mixed>> */
function memoryHostLoadedModels(): array
{
    return [['name' => 'gpt-oss:120b-code', 'size' => 64485704334, 'size_vram' => 64485704334, 'digest' => '4dda6ee4a98ead297f4cdeae389ecdc1963ed4cad90b10c8a11e1cc2a7b0fcaf']];
}

/**
 * Fake the commands and Ollama endpoints HardwareProbe::memoryFacts reads. A null model
 * list makes that endpoint fail. The chat endpoint answers HTTP 500, so a test can tell
 * whether Molly sent the model request.
 *
 * @param  list<array<string, mixed>>|null  $installed
 * @param  list<array<string, mixed>>|null  $loaded
 */
function fakeMemoryHost(?array $installed, ?array $loaded, string $os = 'Darwin', bool $vmStat = true): void
{
    Process::fake([
        'uname -s' => Process::result($os."\n"),
        'sysctl -n hw.memsize' => Process::result("103079215104\n"),
        'sysctl -n hw.pagesize' => Process::result("16384\n"),
        'vm_stat' => $vmStat ? Process::result(memoryHostVmStat()."\n") : Process::result('', 'vm_stat: not permitted', 1),
        'sysctl -n kern.memorystatus_level' => Process::result("84\n"),
        'sysctl -n kern.memorystatus_vm_pressure_level' => Process::result("1\n"),
    ]);
    Http::fake([
        '127.0.0.1:11434/api/tags' => $installed === null ? Http::response('', 500) : Http::response(['models' => $installed]),
        '127.0.0.1:11434/api/ps' => $loaded === null ? Http::response('', 500) : Http::response(['models' => $loaded]),
        '127.0.0.1:11434/api/chat' => Http::response(['error' => 'the chat request was sent'], 500),
    ]);
}

function chatRequested(): bool
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/chat'))->isNotEmpty();
}

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-memory-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/routes');
    File::put($this->workspace.'/routes/web.php', '<?php');
    commitGitWorkspace($this->workspace);
    config([
        'ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434'],
        'molly.agent' => 'ollama',
        'molly.model' => 'gpt-oss:120b',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

function promptThrough(string $site, string $workspace): string
{
    $call = [
        'story' => fn () => app(CreateTaskFromStory::class)->handle('Visitors see a greeting.', $workspace, ['routes/web.php'], 'tests/Feature/GreetingTest.php', 'memory-story'),
        'start' => fn () => app(GenerateChanges::class)->handle('Return Hello.', ['routes/web.php' => '<?php'], 'tests/Feature/GreetingTest.php'),
        'review' => fn () => app(ReviewChanges::class)->handle('Return Hello.', ['routes/web.php' => '<?php'], ['routes/web.php' => "<?php\n"]),
    ][$site];

    try {
        $call();
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }

    throw new RuntimeException('The call site did not fail.');
}

function memoryRefusal(): string
{
    return 'MODEL_MEMORY_INSUFFICIENT: gpt-oss:120b needs 65.4 GB plus 11 GB of headroom, but only 44.1 GB is available. Molly did not ask Ollama to load it. Choose a smaller installed model with php artisan molly:setup, or free memory and try again.';
}

it('refuses a model larger than available memory before Ollama loads it', function (string $site) {
    fakeMemoryHost(memoryHostInstalledModels(), memoryHostLoadedModels());

    $message = promptThrough($site, $this->workspace);

    expect($message)->toBe(memoryRefusal())
        ->and(chatRequested())->toBeFalse()
        ->and(Task::count())->toBe(0)
        ->and(preg_replace('~https?://\S+~', '', $message))->not->toMatch('~(?<![\w.])/[\w.@-]+/~');
})->with(['story', 'start', 'review']);

it('sends the request for a model Ollama already holds, whatever its size', function (string $site) {
    config(['molly.model' => 'gpt-oss:120b-code']);
    fakeMemoryHost(memoryHostInstalledModels(), memoryHostLoadedModels());

    expect(promptThrough($site, $this->workspace))->toStartWith('PROVIDER_ERROR: Ollama answered HTTP 500 for model gpt-oss:120b-code.')
        ->and(chatRequested())->toBeTrue();
})->with(['story', 'start', 'review']);

it('does not refuse when Molly cannot measure memory, the model size, or the loaded models', function (?array $installed, ?array $loaded, string $os, bool $vmStat, string $reason) {
    fakeMemoryHost($installed, $loaded, $os, $vmStat);

    $memory = app(LocalOllama::class)->memory('gpt-oss:120b');

    expect($memory['status'])->toBe('unknown')
        ->and($memory['message'])->toContain($reason)
        ->and($memory['message'])->toContain('does not refuse');
    expect(promptThrough('start', $this->workspace))->toStartWith('PROVIDER_ERROR: Ollama answered HTTP 500')
        ->and(chatRequested())->toBeTrue();
})->with([
    'a host that is not macOS' => [memoryHostInstalledModels(), memoryHostLoadedModels(), 'Linux', true, 'the available memory is unknown'],
    'vm_stat fails' => [memoryHostInstalledModels(), memoryHostLoadedModels(), 'Darwin', false, 'the available memory is unknown'],
    'no installed model list' => [null, memoryHostLoadedModels(), 'Darwin', true, 'Ollama did not report the size of gpt-oss:120b'],
    'no loaded model list' => [memoryHostInstalledModels(), null, 'Darwin', true, 'Ollama did not say which models it holds'],
]);

it('adds molly.memory.headroom_gb to the model size', function (int|float|string $headroom, string $status, string $message) {
    config(['molly.model' => 'custom:40b', 'molly.memory.headroom_gb' => $headroom]);
    fakeMemoryHost(memoryHostInstalledModels(), memoryHostLoadedModels());

    expect(app(LocalOllama::class)->memory('custom:40b'))->toBe(['status' => $status, 'message' => $message]);
})->with([
    'no headroom' => [0, 'fits', 'custom:40b needs 40 GB plus 0 GB of headroom, and 44.1 GB is available.'],
    'a fractional headroom that still fits' => [3.5, 'fits', 'custom:40b needs 40 GB plus 3.5 GB of headroom, and 44.1 GB is available.'],
    'the default 11 GB' => [11, 'exceeds', 'custom:40b needs 40 GB plus 11 GB of headroom, but only 44.1 GB is available. Molly did not ask Ollama to load it. Choose a smaller installed model with php artisan molly:setup, or free memory and try again.'],
    'a numeric string' => ['5', 'exceeds', 'custom:40b needs 40 GB plus 5 GB of headroom, but only 44.1 GB is available. Molly did not ask Ollama to load it. Choose a smaller installed model with php artisan molly:setup, or free memory and try again.'],
]);

it('defaults molly.memory.headroom_gb to 11', function () {
    expect(config('molly.memory.headroom_gb'))->toBe(11);
});

it('refuses an invalid headroom with MEMORY_HEADROOM_INVALID before any request', function (mixed $headroom) {
    config(['molly.memory.headroom_gb' => $headroom]);
    fakeMemoryHost(memoryHostInstalledModels(), memoryHostLoadedModels());

    expect(promptThrough('start', $this->workspace))->toBe('MEMORY_HEADROOM_INVALID: Set molly.memory.headroom_gb to a number of gigabytes, 0 or more.');
    Http::assertNothingSent();
})->with(['a negative number' => -1, 'text' => 'plenty', 'null' => null]);

it('reports model_exceeds_memory in doctor and exits 1', function () {
    fakeMemoryHost(memoryHostInstalledModels(), memoryHostLoadedModels());

    $exit = Artisan::call('molly:doctor', ['--workspace' => __DIR__.'/../..', '--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $checks = array_column($report['checks'], null, 'name');

    expect($exit)->toBe(1)
        ->and($report['ready'])->toBeFalse()
        ->and($checks['Installed model']['code'])->toBe('model_ready')
        ->and($checks['Model memory'])->toBe([
            'name' => 'Model memory',
            'status' => 'failed',
            'code' => 'model_exceeds_memory',
            'message' => substr(memoryRefusal(), strlen('MODEL_MEMORY_INSUFFICIENT: ')),
        ])
        ->and(chatRequested())->toBeFalse();
});

it('reports memory the doctor could not measure as unknown without failing the check', function () {
    fakeMemoryHost(memoryHostInstalledModels(), memoryHostLoadedModels(), 'Linux');

    $report = app(CheckEnvironment::class)->handle(__DIR__.'/../..');
    $check = array_column($report['checks'], null, 'name')['Model memory'];

    expect($check['status'])->toBe('unknown')
        ->and($check['code'])->toBe('model_memory_unknown')
        ->and($report['ready'])->toBe(! in_array('failed', array_column($report['checks'], 'status'), true));
});

it('reports a model that fits or is already loaded as passed in doctor', function (string $model, string $code) {
    config(['molly.model' => $model]);
    fakeMemoryHost(memoryHostInstalledModels(), memoryHostLoadedModels());

    $check = array_column(app(CheckEnvironment::class)->handle(__DIR__.'/../..')['checks'], null, 'name')['Model memory'];

    expect($check['status'])->toBe('passed')->and($check['code'])->toBe($code);
})->with([
    'fits' => ['gpt-oss:20b', 'model_fits_memory'],
    'already loaded' => ['gpt-oss:120b-code', 'model_loaded'],
]);

it('reports an invalid headroom in doctor as memory_headroom_invalid', function () {
    config(['molly.memory.headroom_gb' => -1]);
    fakeMemoryHost(memoryHostInstalledModels(), memoryHostLoadedModels());

    $check = array_column(app(CheckEnvironment::class)->handle(__DIR__.'/../..')['checks'], null, 'name')['Model memory'];

    expect($check)->toMatchArray(['status' => 'failed', 'code' => 'memory_headroom_invalid', 'message' => 'Set molly.memory.headroom_gb to a number of gigabytes, 0 or more.']);
});

it('reads memory without running system_profiler, the ollama binary, or df', function () {
    fakeMemoryHost(memoryHostInstalledModels(), memoryHostLoadedModels());
    // Record every other command too, so a heavier probe would show up in the assertion.
    Process::fake(['*' => Process::result('', 'not simulated', 127)]);

    app(LocalOllama::class)->memory('gpt-oss:120b');

    Process::assertDidntRun(fn ($process): bool => preg_match('/system_profiler|ollama|df -kP|diskutil/', (string) (is_array($process->command) ? implode(' ', $process->command) : $process->command)) === 1);
    Http::assertNotSent(fn (Request $request): bool => ! str_ends_with($request->url(), '/api/tags') && ! str_ends_with($request->url(), '/api/ps'));
});
