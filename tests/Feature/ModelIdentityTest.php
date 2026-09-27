<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Actions\RecordModelIdentity;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Actions\VerifyChanges;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-identity-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/app');
    File::put($this->workspace.'/app/Greeting.php', '<?php return null;');
    writeProtectedTest($this->workspace);
    commitGitWorkspace($this->workspace);
    config(['ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434'], 'molly.agent' => 'ollama', 'molly.model' => 'qwen3:8b']);
    $this->digest = str_repeat('d', 64);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

function fakeOllamaIdentity(string $digest, bool $loaded = true): void
{
    Http::fake([
        '127.0.0.1:11434/api/version' => Http::response(['version' => '0.12.3']),
        '127.0.0.1:11434/api/show' => Http::response([
            'details' => ['family' => 'qwen3', 'parameter_size' => '8.2B', 'quantization_level' => 'Q4_K_M'],
            'model_info' => ['general.architecture' => 'qwen3', 'qwen3.context_length' => 40960],
        ]),
        '127.0.0.1:11434/api/ps' => Http::response(['models' => $loaded ? [
            ['name' => 'other:1b', 'digest' => str_repeat('0', 64)],
            ['name' => 'qwen3:8b', 'model' => 'qwen3:8b', 'digest' => $digest, 'size' => 6_000_000_000, 'size_vram' => 5_900_000_000, 'context_length' => 8192],
        ] : []]),
        '127.0.0.1:11434/api/tags' => Http::response(['models' => [['name' => 'qwen3:8b', 'digest' => $digest]]]),
    ]);
}

it('records Ollama model identity in the run report and every receipt', function () {
    fakeOllamaIdentity($this->digest);
    $this->mock(MeasureComplexity::class)->shouldReceive('handle')->twice()->andReturn(['status' => 'ok', 'probes' => []]);
    $this->mock(GenerateChanges::class)->shouldReceive('handle')->once()->andReturn(['summary' => 'Return Hello.', 'files' => [['path' => 'app/Greeting.php', 'content' => '<?php return "Hello";']]]);
    $this->mock(VerifyChanges::class)->shouldReceive('handle')->once()->andReturn(['status' => 'passed', 'tests' => 1, 'assertions' => 1, 'identified_required_test' => true]);
    $this->mock(ReviewChanges::class)->makePartial()->shouldReceive('handle')->once()->andReturn(['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'No finding.']), 'findings' => []]);

    $run = app(RunTask::class)->handle('Return Hello.', $this->workspace, ['app/Greeting.php'], 'tests/GreetingTest.php');
    $identity = $run->report['model_identity'];

    expect($run->status)->toBe('completed')
        ->and($identity)->toMatchArray([
            'provider' => 'ollama',
            'model' => 'qwen3:8b',
            'ollama_version' => '0.12.3',
            'digest' => $this->digest,
            'family' => 'qwen3',
            'parameter_size' => '8.2B',
            'quantization' => 'Q4_K_M',
            'context_length' => 8192,
            'max_context_length' => 40960,
            'size_bytes' => 6_000_000_000,
            'vram_bytes' => 5_900_000_000,
            'concurrency' => 'unavailable',
        ])
        ->and($identity['generation_ms'])->toBeInt()
        ->and($identity['sources'])->toBe(['version' => 'ok', 'show' => 'ok', 'ps' => 'ok'])
        ->and($run->report['verification_receipts'])->not->toBeEmpty();
    foreach ($run->report['verification_receipts'] as $receipt) {
        expect($receipt['context']['model_identity'])->toBe($identity)
            ->and(json_decode(File::get($receipt['path']), true)['context']['model_identity']['digest'])->toBe($this->digest);
    }
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:11434/api/show' && $request['model'] === 'qwen3:8b');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/tags'));
});

it('reads the digest from installed models when the model is no longer loaded', function () {
    fakeOllamaIdentity($this->digest, loaded: false);

    $identity = app(RecordModelIdentity::class)->handle(1200);

    expect($identity['digest'])->toBe($this->digest)
        ->and($identity['context_length'])->toBe('unavailable')
        ->and($identity['vram_bytes'])->toBe('unavailable')
        ->and($identity['max_context_length'])->toBe(40960)
        ->and($identity['generation_ms'])->toBe(1200)
        ->and($identity['sources']['tags'])->toBe('ok');
});

it('records unavailable values when Ollama does not answer', function () {
    Http::fake(['*' => Http::failedConnection()]);

    $identity = app(RecordModelIdentity::class)->handle();

    foreach (['ollama_version', 'digest', 'family', 'parameter_size', 'quantization', 'context_length', 'max_context_length', 'size_bytes', 'vram_bytes', 'concurrency', 'generation_ms'] as $key) {
        expect($identity[$key])->toBe('unavailable');
    }
    expect($identity['model'])->toBe('qwen3:8b')
        ->and($identity['sources'])->toBe(['version' => 'ConnectionException', 'show' => 'ConnectionException', 'ps' => 'ConnectionException', 'tags' => 'ConnectionException']);
});

it('does not call a non-loopback URL or a different agent', function () {
    Http::fake();

    config(['ai.providers.ollama.url' => 'https://ollama.example.com']);
    $remote = app(RecordModelIdentity::class)->handle();

    expect($remote['digest'])->toBe('unavailable')
        ->and($remote['sources']['ollama'])->toStartWith('LOCAL_PROVIDER_INVALID');

    config(['molly.agent' => 'amp']);
    expect(app(RecordModelIdentity::class)->handle())->toBeNull();
    Http::assertNothingSent();
});
