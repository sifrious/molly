<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
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
    public function handle(string $prompt, string $workspace, array $paths, string $testPath, array $source = [], ?string $nickname = null): Task
    {
        if (trim($prompt) === '' || strlen($prompt) > 8192) {
            throw new RuntimeException('PROMPT_INVALID: Describe the task in 1 to 8192 bytes.');
        }

        $files = new Workspace($workspace);

        $paths = $files->taskPaths($paths, $testPath);

        $snapshot = $files->snapshot($files->read($paths));

        try {
            json_encode($source, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('SOURCE_INVALID: Source metadata must contain valid JSON values.', 0, $exception);
        }

        $nickname = $nickname === null || trim($nickname) === '' ? null : Task::validateNickname($nickname);

        try {
            return Task::create([
                'nickname' => $nickname,
                'prompt' => $prompt,
                'workspace' => $files->path,
                'paths' => $paths,
                'test_path' => $testPath,
                'source' => $source === [] ? null : $source,
                'context_snapshot' => $snapshot,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new RuntimeException('TASK_NAME_TAKEN: Another task already uses that name.', 0, $exception);
        }
    }
}
