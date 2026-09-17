<?php

namespace Sifrious\Molly\Actions;

use JsonException;
use RuntimeException;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;

class CreateTask
{
    /**
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $source
     */
    public function handle(string $prompt, string $workspace, array $paths, string $testPath, array $source = []): Task
    {
        if (trim($prompt) === '' || strlen($prompt) > 8192) {
            throw new RuntimeException('PROMPT_INVALID: Describe the task in 1 to 8192 bytes.');
        }

        $files = new Workspace($workspace);

        if (! in_array($testPath, $paths, true) || ! str_starts_with($testPath, 'tests/') || ! str_ends_with($testPath, '.php')) {
            throw new RuntimeException('TEST_PATH_INVALID: Include the required PHP test file in --file and select it with --test.');
        }

        if (! array_is_list($paths) || count(array_filter($paths, is_string(...))) !== count($paths)) {
            throw new RuntimeException('FILES_INVALID: Select a list of file paths.');
        }

        $files->read($paths);

        try {
            json_encode($source, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('SOURCE_INVALID: Source metadata must contain valid JSON values.', 0, $exception);
        }

        return Task::create([
            'prompt' => $prompt,
            'workspace' => $files->path,
            'paths' => $paths,
            'test_path' => $testPath,
            'source' => $source === [] ? null : $source,
        ]);
    }
}
