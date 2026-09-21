<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use JsonException;
use RuntimeException;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;
use Sifrious\Molly\Workspace\BindWorkspaceReference;

class CreateTask
{
    public function __construct(
        private RefreshProjectJournal $journal,
        private RestoreTaskBaseline $baseline,
        private CaptureComponentPreview $preview,
        private RecordLifecycleEvent $lifecycle,
        private BindWorkspaceReference $bindWorkspace,
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

        $reference = $this->bindWorkspace->handle($workspace);
        $reference->assertAvailableForExecution();
        // Normalize trailing "/." and similar; persist realpath as observed metadata.
        $files = new Workspace($workspace);
        $workspace = $files->path;

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
                'workspace' => $workspace,
                'project_id' => $reference->project->id,
                'workspace_id' => $reference->workspace->id,
                'repository_id' => $reference->repositoryId,
                'repository_remote_identity' => $reference->repositoryRemoteIdentity,
                'checkout_id' => $reference->checkoutId,
                'checkout_kind' => $reference->checkoutKind,
                'base_sha' => $reference->head->sha,
                'branch' => $reference->branch,
                'bloom_workspace_id' => $reference->bloomWorkspaceId,
                'identity_status' => 'bound',
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
