<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use JsonException;
use RuntimeException;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;

class CreateTask
{
    public function __construct(
        private RefreshProjectJournal $journal,
        private RestoreTaskBaseline $baseline,
        private CaptureComponentPreview $preview,
        private RecordLifecycleEvent $lifecycle,
    ) {}

    /**
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $source
     */
    public function handle(string $prompt, string $workspace, array $paths, string $testPath, array $source = [], ?string $nickname = null, bool $allowTestEdits = false): Task
    {
        if (trim($prompt) === '' || strlen($prompt) > 8192) {
            throw new RuntimeException('PROMPT_INVALID: Describe the task in 1 to 8192 bytes.');
        }

        $files = new Workspace($workspace);

        $paths = $files->taskPaths($paths, $testPath, $allowTestEdits);
        $testDigest = $files->testDigest($testPath);
        if (! $allowTestEdits && $testDigest === null) {
            throw new RuntimeException('PROTECTED_TEST_MISSING: Create and approve the required Pest test before the implementation turn.');
        }

        $contents = [...$files->read($paths), ...$files->readProtectedTest($testPath)];
        $snapshot = $files->snapshot($contents);
        $snapshot['preview'] = $this->preview->handle($files->path, $contents, 'task_creation');

        try {
            json_encode($source, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('SOURCE_INVALID: Source metadata must contain valid JSON values.', 0, $exception);
        }

        $nickname = $nickname === null || trim($nickname) === '' ? null : Task::validateNickname($nickname);

        try {
            $task = Task::create([
                'nickname' => $nickname,
                'prompt' => $prompt,
                'workspace' => $files->path,
                'paths' => $paths,
                'test_path' => $testPath,
                'test_digest' => $testDigest,
                'allow_test_edits' => $allowTestEdits,
                'source' => $source === [] ? null : $source,
                'context_snapshot' => $snapshot,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            throw new RuntimeException('TASK_NAME_TAKEN: Another task already uses that name.', 0, $exception);
        }

        $task = $this->journal->handle($task);
        $this->baseline->store($task, $contents);
        $this->lifecycle->handle($files->path, LifecycleEventType::Created, $task->id);

        return $task;
    }
}
