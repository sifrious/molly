<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Sifrious\Molly\Agents\AmpResponse;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\LocalOllama;

class GenerateChanges
{
    public function __construct(
        private CollectRunKnowledge $knowledge,
        private CollectNativePhpKnowledge $nativephp,
    ) {}

    /**
     * @param  array<string, string|null>  $files
     * @param  array<string, mixed>|null  $previousAttempt
     * @return array{summary: string, files: list<array{path: string, content: string}>}
     */
    public function handle(string $prompt, array $files, string $testPath, ?array $previousAttempt = null, bool $allowTestEdits = false, ?string $testDigest = null): array
    {
        $input = json_encode([
            'task' => $prompt,
            'allowed_files' => $files,
            'required_test' => $testPath,
            'protected_test' => ['path' => $testPath, 'digest' => $testDigest, 'writable' => $allowTestEdits],
            'laravel_knowledge' => $this->knowledge->handle($prompt, $files, $testPath),
            'nativephp_knowledge' => $this->nativephp->handle($prompt, $files, $testPath),
            ...($previousAttempt === null ? [] : ['previous_attempt' => $previousAttempt]),
        ], JSON_THROW_ON_ERROR);
        if (config('molly.agent', 'ollama') === 'amp') {
            $result = app(AmpResponse::class)->prompt(new ChangeWriter, $input);
        } elseif (config('molly.agent', 'ollama') === 'ollama') {
            LocalOllama::validate();
            $response = ChangeWriter::make()->prompt(
                $input, provider: 'ollama', model: config('molly.model'), timeout: config('molly.timeout'),
            );
            $result = $response instanceof StructuredAgentResponse ? $response->toArray() : [];
        } else {
            throw new RuntimeException('AGENT_INVALID: Choose amp or ollama for molly.agent.');
        }
        $validator = Validator::make($result, [
            'summary' => ['required', 'string'],
            'files' => ['required', 'array', 'list', 'min:1'],
            'files.*' => ['required', 'array:path,content'],
            'files.*.path' => ['required', 'string', 'distinct:strict'],
            'files.*.content' => ['present', 'string'],
        ]);

        if ($validator->fails()) {
            throw new RuntimeException('GENERATION_INVALID: The model did not return a valid change proposal.');
        }

        foreach ($result['files'] as $file) {
            if (! $allowTestEdits && $file['path'] === $testPath) {
                throw new RuntimeException('PROTECTED_TEST_CHANGED: The required Pest test is read-only for this implementation run.');
            }
            if (! array_key_exists($file['path'], $files)) {
                throw new RuntimeException('GENERATION_INVALID: The model proposed a file outside the allowed paths.');
            }
        }

        return ['summary' => $result['summary'], 'files' => $result['files']];
    }
}
