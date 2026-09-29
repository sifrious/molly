<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Sifrious\Molly\Actions\CheckModelReadiness;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\ReadinessCheck;
use Sifrious\Molly\Agents\TarpitReviewer;
use Sifrious\Molly\ModelFit\ModelRecords;
use Sifrious\Molly\Settings\SettingsStore;

/*
 * The readiness check runs through LocalOllama, so the host replay supplies the memory facts
 * its load check reads. Model answers come from Laravel AI agent fakes, or, where the request
 * itself matters, from a faked Ollama chat endpoint.
 */

beforeEach(function () {
    $this->home = sys_get_temp_dir().'/molly-ready-home-'.bin2hex(random_bytes(6));
    app()->instance(SettingsStore::class, new SettingsStore($this->home));
    replayHardwareFixture('m3-ultra-96gb', http: [
        '/api/ps' => ['status' => 200, 'json' => ['models' => [['name' => 'gpt-oss:20b', 'size' => 14000000000, 'size_vram' => 13900000000, 'digest' => GPT_OSS_20B_DIGEST]]]],
    ]);
    config(['molly.agent' => 'amp', 'molly.model' => 'something-else:1b']);
});

afterEach(function () {
    File::deleteDirectory($this->home);
});

function readinessCartWithTotal(): string
{
    return "<?php\n\nnamespace App;\n\nclass Cart\n{\n    /** @param list<array{name: string, price: int}> \$lines */\n    public function __construct(private array \$lines) {}\n\n    public function total(): int\n    {\n        return array_sum(array_column(\$this->lines, 'price'));\n    }\n}\n";
}

/** @return array<string, mixed> */
function readinessCleanReview(): array
{
    return ['checks' => array_fill_keys(range('A', 'G'), ['status' => 'clean', 'evidence' => 'Nothing to report in app/Cart.php.']), 'findings' => []];
}

function runReadinessCheck(): array
{
    return app(CheckModelReadiness::class)->handle('gpt-oss:20b', 'sha256:'.GPT_OSS_20B_DIGEST, '0.34.4', 'sha256:catalogue');
}

it('passes only when the bounded inference and the Molly task both pass, and saves the record', function () {
    ReadinessCheck::fake([['answer' => 42]])->preventStrayPrompts();
    ChangeWriter::fake([['summary' => 'Add Cart::total().', 'files' => [['path' => 'app/Cart.php', 'content' => readinessCartWithTotal()]]]])->preventStrayPrompts();
    TarpitReviewer::fake([readinessCleanReview()])->preventStrayPrompts();

    $record = runReadinessCheck();

    expect($record)->toMatchArray(['model' => 'gpt-oss:20b', 'digest' => 'sha256:'.GPT_OSS_20B_DIGEST, 'runtime' => ['name' => 'ollama', 'version' => '0.34.4'], 'passed' => true])
        ->and($record['checks']['bounded_inference'])->toMatchArray(['passed' => true, 'answer' => 42, 'max_tokens' => 1024, 'timeout_s' => 180, 'error' => null])
        ->and($record['checks']['bounded_inference']['latency_ms'])->toBeInt()
        ->and($record['checks']['molly_task'])->toMatchArray(['passed' => true, 'error' => null])
        ->and($record['checks']['molly_task']['proposal'])->toMatchArray(['files' => ['app/Cart.php'], 'parses' => true, 'defines_total' => true])
        ->and($record['checks']['molly_task']['review'])->toMatchArray(['checks' => 7, 'findings' => 0])
        ->and($record['memory'])->toBe(['loaded_size_bytes' => 14000000000, 'loaded_vram_bytes' => 13900000000, 'available_bytes_after' => 40525152256])
        ->and(app(ModelRecords::class)->readiness()['gpt-oss:20b'])->toBe($record)
        ->and(config('molly.agent'))->toBe('amp')
        ->and(config('molly.model'))->toBe('something-else:1b');
});

it('bounds the inference request by tokens and sends every step through the local Ollama chat endpoint', function () {
    $proposal = json_encode(['summary' => 'Add Cart::total().', 'files' => [['path' => 'app/Cart.php', 'content' => readinessCartWithTotal()]]]);
    $reply = fn (string $content): array => ['model' => 'gpt-oss:20b', 'message' => ['role' => 'assistant', 'content' => $content], 'done' => true, 'done_reason' => 'stop'];
    Http::fake(['localhost:11434/api/chat' => Http::sequence()
        ->push($reply('{"answer":42}'))
        ->push($reply($proposal))
        ->push($reply(json_encode(readinessCleanReview())))]);

    $record = runReadinessCheck();
    $chats = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/chat'))->map(fn (array $pair): Request => $pair[0])->values();

    expect($record['passed'])->toBeTrue()
        ->and($chats)->toHaveCount(3)
        ->and($chats[0]['model'])->toBe('gpt-oss:20b')
        ->and($chats[0]['options']['num_predict'])->toBe(1024)
        ->and($chats[0]['format']['properties'])->toHaveKey('answer')
        ->and($chats->pluck('model')->unique()->all())->toBe(['gpt-oss:20b']);
});

it('fails the check when the model answers the known question wrongly, and skips the task', function () {
    ReadinessCheck::fake([['answer' => 41]])->preventStrayPrompts();
    ChangeWriter::fake()->preventStrayPrompts();

    $record = runReadinessCheck();

    expect($record['passed'])->toBeFalse()
        ->and($record['checks']['bounded_inference'])->toMatchArray(['passed' => false, 'answer' => 41, 'error' => 'READINESS_ANSWER_WRONG: The model answered 41 instead of 42.'])
        ->and($record['checks']['molly_task'])->toBe(['passed' => false, 'error' => 'READINESS_SKIPPED: The bounded inference step failed, so Molly did not run the task step.']);
});

it('does not accept a chat reply that does not do the task', function (string $content, string $error) {
    ReadinessCheck::fake([['answer' => 42]])->preventStrayPrompts();
    ChangeWriter::fake([['summary' => 'Hello.', 'files' => [['path' => 'app/Cart.php', 'content' => $content]]]])->preventStrayPrompts();
    TarpitReviewer::fake()->preventStrayPrompts();

    $record = runReadinessCheck();

    expect($record['passed'])->toBeFalse()
        ->and($record['checks']['bounded_inference']['passed'])->toBeTrue()
        ->and($record['checks']['molly_task'])->toMatchArray(['passed' => false, 'error' => $error, 'review' => null]);
})->with([
    'a greeting' => ["<?php\n\nnamespace App;\n\nclass Cart\n{\n    public function greet(): string\n    {\n        return 'Hello';\n    }\n}\n", 'READINESS_TASK_INVALID: The proposed app/Cart.php does not define Cart::total().'],
    'invalid PHP' => ["<?php\n\nclass Cart { public function total(): int { return 1 }\n", 'READINESS_TASK_INVALID: The proposed app/Cart.php is not valid PHP.'],
]);

it('fails the check when the Tarpit review is incomplete', function () {
    ReadinessCheck::fake([['answer' => 42]])->preventStrayPrompts();
    ChangeWriter::fake([['summary' => 'Add Cart::total().', 'files' => [['path' => 'app/Cart.php', 'content' => readinessCartWithTotal()]]]])->preventStrayPrompts();
    TarpitReviewer::fake([['checks' => ['A' => ['status' => 'clean', 'evidence' => 'ok']], 'findings' => []]])->preventStrayPrompts();

    $record = runReadinessCheck();

    expect($record['passed'])->toBeFalse()
        ->and($record['checks']['molly_task']['error'])->toStartWith('REVIEW_INVALID:')
        ->and($record['checks']['molly_task']['proposal']['defines_total'])->toBeTrue();
});

it('records a memory refusal as the reason the check failed', function () {
    replayHardwareFixture('macbook-air-8gb', http: ['/api/ps' => ['status' => 200, 'json' => ['models' => []]]]);
    ReadinessCheck::fake()->preventStrayPrompts();

    $record = runReadinessCheck();

    expect($record['passed'])->toBeFalse()
        ->and($record['checks']['bounded_inference']['error'])->toStartWith('MODEL_MEMORY_INSUFFICIENT: gpt-oss:20b needs 13.8 GB plus 11 GB of headroom');
});
