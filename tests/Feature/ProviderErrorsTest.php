<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Sifrious\Molly\Actions\CreateTaskFromStory;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Models\Task;

/*
 * Every local model call goes through LocalOllama::prompt. These tests send real
 * Laravel AI requests to a faked Ollama chat endpoint and check the coded message
 * each provider failure becomes for story, start (generation), and review.
 */

beforeEach(function () {
    $this->workspace = sys_get_temp_dir().'/molly-provider-'.Str::uuid();
    File::ensureDirectoryExists($this->workspace.'/routes');
    File::put($this->workspace.'/routes/web.php', '<?php');
    commitGitWorkspace($this->workspace);
    config([
        'ai.providers.ollama' => ['driver' => 'ollama', 'url' => 'http://127.0.0.1:11434'],
        'molly.agent' => 'ollama',
        'molly.model' => 'absent-model:1b',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->workspace);
});

/** @return array<string, Closure(): mixed> */
function providerCallSites(): array
{
    return [
        'story' => fn (string $workspace) => app(CreateTaskFromStory::class)->handle('Visitors see a greeting.', $workspace, ['routes/web.php'], 'tests/Feature/GreetingTest.php', 'provider-story'),
        'start' => fn () => app(GenerateChanges::class)->handle('Return Hello.', ['routes/web.php' => '<?php'], 'tests/Feature/GreetingTest.php'),
        'review' => fn () => app(ReviewChanges::class)->handle('Return Hello.', ['routes/web.php' => '<?php'], ['routes/web.php' => "<?php\n"]),
    ];
}

function callProvider(string $site, string $workspace): string
{
    try {
        providerCallSites()[$site]($workspace);
    } catch (Throwable $exception) {
        return $exception->getMessage();
    }

    throw new RuntimeException('The call site did not fail.');
}

/** The message names no PHP class, file, line, stack frame, or absolute path. A loopback URL is allowed. */
function expectSafeProviderMessage(string $message): void
{
    expect($message)->not->toContain('\\')
        ->not->toContain('.php on line')
        ->not->toContain('Stack trace')
        ->not->toContain('vendor')
        ->and(preg_replace('~https?://\S+~', '', $message))->not->toMatch('~(?<![\w.])/[\w.@-]+/~');
}

dataset('model call sites', ['story', 'start', 'review']);

it('reports a missing Ollama model as MODEL_MISSING', function (string $site) {
    Http::fake(['127.0.0.1:11434/api/chat' => Http::response(['error' => "model 'absent-model:1b' not found"], 404)]);

    expect(callProvider($site, $this->workspace))
        ->toBe('MODEL_MISSING: Ollama has no model named absent-model:1b. Run ollama list, or choose an installed model with php artisan molly:setup.');
    expect(Task::count())->toBe(0);
})->with('model call sites');

it('reports an Ollama body that is not a chat response as PROVIDER_RESPONSE_INVALID without class names or paths', function (string $site, string $body) {
    Http::fake(['127.0.0.1:11434/api/chat' => Http::response($body, 200)]);

    $message = callProvider($site, $this->workspace);

    expect($message)->toBe('PROVIDER_RESPONSE_INVALID: Ollama answered, but the body was not a chat response Molly can read. Molly used none of it. Check that OLLAMA_URL points at Ollama and try again.')
        ->not->toContain('Error');
    expectSafeProviderMessage($message);
    expect(Task::count())->toBe(0);
})->with('model call sites')->with([
    'truncated JSON' => '{"model":"absent-model:1b","message":{"content":"{',
    'plain text' => 'upstream said something unexpected',
    'empty body' => '',
    'JSON string' => '"hello"',
    'empty object' => '{}',
    'tool calls that are not a list' => '{"model":"absent-model:1b","message":{"role":"assistant","content":"{}","tool_calls":"none"}}',
]);

it('reports an Ollama HTTP error as PROVIDER_ERROR with the status and without the provider body', function (string $site, int $status, string $body) {
    Http::fake(['127.0.0.1:11434/api/chat' => Http::response($body, $status)]);

    $message = callProvider($site, $this->workspace);

    expect($message)->toBe('PROVIDER_ERROR: Ollama answered HTTP '.$status.' for model absent-model:1b. Molly used none of the reply. Check the Ollama server log, then try again.')
        ->not->toContain('injected');
    expectSafeProviderMessage($message);
    expect(Task::count())->toBe(0);
})->with('model call sites')->with([
    'internal error' => [500, '{"error":"injected internal error at /Users/someone/.ollama/models/blobs"}'],
    'overloaded' => [503, 'injected upstream busy page'],
    'rate limited' => [429, '{"error":"injected rate limit"}'],
    'rejected request' => [400, '{"error":"injected invalid options"}'],
    'unknown route' => [404, 'injected 404 page not from the model API'],
]);

it('reports an error field in a successful Ollama reply as PROVIDER_ERROR without echoing it', function (string $site) {
    Http::fake(['127.0.0.1:11434/api/chat' => Http::response(['error' => 'injected failure reading /Users/someone/.ollama/models/blobs/sha256-0'], 200)]);

    $message = callProvider($site, $this->workspace);

    expect($message)->toBe('PROVIDER_ERROR: Ollama reported an error for model absent-model:1b instead of a chat response. Molly used none of the reply. Check the Ollama server log, then try again.')
        ->not->toContain('injected');
    expectSafeProviderMessage($message);
    expect(Task::count())->toBe(0);
})->with('model call sites');

/** A loopback port with nothing listening on it, so a connection is refused. */
function refusedLoopbackPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) parse_url('tcp://'.stream_socket_get_name($socket, false), PHP_URL_PORT);
    fclose($socket);

    return $port;
}

it('reports a slow Ollama as PROVIDER_TIMEOUT and names molly.timeout', function (string $site) {
    // A loopback server that accepts the connection and never answers.
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT);
    config(['ai.providers.ollama.url' => 'http://127.0.0.1:'.$port, 'molly.timeout' => 1]);
    Http::allowStrayRequests(['http://127.0.0.1:'.$port.'/api/chat']);

    try {
        $started = microtime(true);
        $message = callProvider($site, $this->workspace);
        $elapsed = microtime(true) - $started;
    } finally {
        fclose($server);
    }

    expect($message)->toBe('PROVIDER_TIMEOUT: Ollama did not answer for model absent-model:1b within molly.timeout, 1 second. Molly stopped waiting. Try a smaller task or a faster model, or raise molly.timeout in config/molly.php.')
        ->and($elapsed)->toBeLessThan(10.0);
    expectSafeProviderMessage($message);
    expect(Task::count())->toBe(0);
})->with('model call sites');

it('reports a refused connection as PROVIDER_UNREACHABLE, not as a timeout', function (string $site) {
    $port = refusedLoopbackPort();
    config(['ai.providers.ollama.url' => 'http://127.0.0.1:'.$port]);
    Http::allowStrayRequests(['http://127.0.0.1:'.$port.'/api/chat']);

    $message = callProvider($site, $this->workspace);

    expect($message)->toBe('PROVIDER_UNREACHABLE: Molly could not connect to Ollama at http://127.0.0.1:'.$port.'. Start Ollama with ollama serve, or fix OLLAMA_URL.');
    expectSafeProviderMessage($message);
    expect(Task::count())->toBe(0);
})->with('model call sites');

it('prints a provider failure from molly:story as one coded stderr line and exits 1', function () {
    $database = sys_get_temp_dir().'/molly-provider-'.Str::uuid().'.sqlite';
    touch($database);
    $url = 'http://127.0.0.1:'.refusedLoopbackPort();
    $environment = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'MOLLY_AGENT' => 'ollama', 'MOLLY_LOCAL_MODEL' => 'absent-model:1b', 'OLLAMA_URL' => $url];

    try {
        testbenchProcess(['migrate', '--force'], $environment)->mustRun();
        $process = testbenchProcess(['molly:story', 'Visitors see a greeting.', '--workspace='.$this->workspace, '--file=routes/web.php', '--test=tests/Feature/GreetingTest.php', '--json', '--no-interaction'], $environment);
        $process->run();
    } finally {
        File::delete($database);
    }

    $expected = 'PROVIDER_UNREACHABLE: Molly could not connect to Ollama at '.$url.'. Start Ollama with ollama serve, or fix OLLAMA_URL.';
    expect($process->getExitCode())->toBe(1)
        ->and(trim($process->getErrorOutput()))->toBe($expected)
        ->and(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['id' => null, 'status' => 'error', 'error' => $expected]);
});
