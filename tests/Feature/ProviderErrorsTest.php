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
