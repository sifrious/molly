<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Sifrious\Molly\Agents\ChangeWriter;
use Sifrious\Molly\Agents\LocalOllama;

class GenerateChanges
{
    /**
     * @param  array<string, string|null>  $files
     * @return array{summary: string, files: list<array{path: string, content: string}>}
     */
    public function handle(string $prompt, array $files, string $testPath): array
    {
        LocalOllama::validate();
        $response = ChangeWriter::make()->prompt(
            json_encode(['task' => $prompt, 'allowed_files' => $files, 'required_test' => $testPath], JSON_THROW_ON_ERROR),
            provider: 'ollama', model: config('molly.model'), timeout: config('molly.timeout'),
        );
        $result = $response instanceof StructuredAgentResponse ? $response->toArray() : [];
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
            if (! array_key_exists($file['path'], $files)) {
                throw new RuntimeException('GENERATION_INVALID: The model proposed a file outside the allowed paths.');
            }
        }

        return ['summary' => $result['summary'], 'files' => $result['files']];
    }
}
