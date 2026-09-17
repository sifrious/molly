<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;
use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\TarpitReviewer;

function cleanMollyReview(): array
{
    $checks = [];
    foreach (range('A', 'G') as $code) {
        $checks[$code] = ['status' => 'clean', 'evidence' => 'No applicable complexity in the supplied function.'];
    }

    return ['checks' => $checks, 'findings' => []];
}

it('returns a proposal through one local model request', function (): void {
    Http::preventStrayRequests();
    $proposal = ['summary' => 'Return the greeting.', 'files' => [['path' => 'src/Greeting.php', 'content' => '<?php return "Hello";']]];
    ChangeWriter::fake([$proposal])->preventStrayPrompts();

    $result = app(GenerateChanges::class)->handle('Add a greeting.', ['src/Greeting.php' => null], 'tests/GreetingTest.php');

    expect($result)->toBe($proposal);
    ChangeWriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->provider->name() === 'ollama'
        && $prompt->model === config('molly.model') && $prompt->timeout === config('molly.timeout')
        && json_decode($prompt->prompt, true)['required_test'] === 'tests/GreetingTest.php');
    ChangeWriter::assertPromptedTimes(1);
});

it('rejects malformed proposals and undeclared file changes', function (mixed $proposal): void {
    Http::preventStrayRequests();
    ChangeWriter::fake([$proposal])->preventStrayPrompts();

    expect(fn () => app(GenerateChanges::class)->handle('Fix greeting.', ['src/Greeting.php' => null], 'tests/GreetingTest.php'))
        ->toThrow(RuntimeException::class, 'GENERATION_INVALID');
})->with([
    'unstructured text' => ['plain text'],
    'missing files' => [['summary' => 'Done']],
    'blank summary' => [['summary' => ' ', 'files' => [['path' => 'src/Greeting.php', 'content' => 'ok']]]],
    'undeclared path' => [['summary' => 'Done', 'files' => [['path' => '../outside.php', 'content' => 'oops']]]],
    'duplicate path' => [['summary' => 'Done', 'files' => [['path' => 'src/Greeting.php', 'content' => 'one'], ['path' => 'src/Greeting.php', 'content' => 'two']]]],
]);

it('refuses remote providers before a model request', function (string $key, mixed $value): void {
    Http::preventStrayRequests();
    config()->set($key, $value);
    ChangeWriter::fake()->preventStrayPrompts();
    TarpitReviewer::fake()->preventStrayPrompts();

    expect(fn () => app(GenerateChanges::class)->handle('Task', [], 'tests/Test.php'))->toThrow(RuntimeException::class, 'LOCAL_PROVIDER_INVALID');
    expect(fn () => app(ReviewChanges::class)->handle('Task', [], []))->toThrow(RuntimeException::class, 'LOCAL_PROVIDER_INVALID');
    ChangeWriter::assertNeverPrompted();
    TarpitReviewer::assertNeverPrompted();
})->with([
    ['ai.providers.ollama.driver', 'openai'],
    ['ai.providers.ollama.url', 'https://ollama.com'],
    ['ai.providers.ollama.url', 'http://localhost.evil.test:11434'],
    ['ai.providers.ollama.url', 'http://user@localhost:11434'],
    ['ai.providers.ollama.url', 'http://localhost:11434/remote'],
    ['molly.model', 'qwen3.5:cloud'],
    ['molly.timeout', 0],
]);

it('returns all seven checks and scoped accidental complexity findings', function (): void {
    Http::preventStrayRequests();
    $review = cleanMollyReview();
    $review['checks']['E'] = ['status' => 'findings', 'evidence' => 'GreetingManager wraps one function without a current need.'];
    $review['findings'][] = [
        'code' => 'E', 'classification' => 'accidental', 'severity' => 'blocking',
        'path' => 'src/Greeting.php', 'line' => 2, 'problem' => 'The manager adds a needless call.',
        'recommendation' => 'Call the existing function directly.',
    ];
    TarpitReviewer::fake([$review])->preventStrayPrompts();

    $result = app(ReviewChanges::class)->handle('Add a greeting.', ['src/Greeting.php' => null], ['src/Greeting.php' => "<?php\nclass GreetingManager {}"]);

    expect($result)->toBe($review);
    TarpitReviewer::assertPromptedTimes(1);
});

it('returns a scoped clean review', function (): void {
    Http::preventStrayRequests();
    $review = cleanMollyReview();
    TarpitReviewer::fake([$review])->preventStrayPrompts();

    expect(app(ReviewChanges::class)->handle('Task', [], ['src/Greeting.php' => '<?php']))->toBe($review);
});

it('rejects incomplete or contradictory review output', function (string $defect): void {
    Http::preventStrayRequests();
    $review = cleanMollyReview();
    if ($defect === 'missing check') {
        unset($review['checks']['G']);
    } elseif ($defect === 'blank evidence') {
        $review['checks']['B']['evidence'] = ' ';
    } elseif ($defect === 'missing finding') {
        $review['checks']['C']['status'] = 'findings';
    } else {
        $review['checks']['E']['status'] = 'findings';
        $review['findings'][] = [
            'code' => 'E', 'classification' => $defect === 'essential blocker' ? 'essential' : 'accidental',
            'severity' => 'blocking', 'path' => $defect === 'unknown file' ? 'secret.php' : 'src/Greeting.php',
            'line' => $defect === 'bad line' ? 99 : 1, 'problem' => 'A needless wrapper.', 'recommendation' => 'Remove the wrapper.',
        ];
        if ($defect === 'hidden finding') {
            $review['checks']['E']['status'] = 'clean';
        }
    }
    TarpitReviewer::fake([$review])->preventStrayPrompts();

    expect(fn () => app(ReviewChanges::class)->handle('Task', [], ['src/Greeting.php' => '<?php']))
        ->toThrow(RuntimeException::class, 'REVIEW_INVALID');
})->with(['missing check', 'blank evidence', 'missing finding', 'unknown file', 'bad line', 'essential blocker', 'hidden finding']);
