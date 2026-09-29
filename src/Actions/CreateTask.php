<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
     * Pass requireRedBaseline: false to let the implementation start without a
     * RED run of the locked test. Molly records that choice on the task.
     *
     * @param  list<string>  $paths
     * @param  array<string, mixed>  $source
     */
    public function handle(string $prompt, string $workspace, array $paths, string $testPath, array $source = [], ?string $nickname = null, bool $allowTestEdits = false, bool $requireRedBaseline = true): Task
    {
        if (trim($prompt) === '' || strlen($prompt) > 8192) {
            throw new RuntimeException('PROMPT_INVALID: Describe the task in 1 to 8192 bytes.');
        }

        // Check the task's own inputs before binding, which runs Git in the workspace. The
        // workspace is canonical from here on: a relative --workspace, a trailing "/.", and
        // similar forms resolve once, and the task saves the real path.
        $files = new Workspace($workspace);
        $workspace = $files->path;
        $paths = $files->taskPaths($paths, $testPath, $allowTestEdits);
        $testDigest = $files->testDigest($testPath);
        if (! $allowTestEdits && $testDigest === null) {
            throw $files->missingProtectedTest($testPath);
        }

        $reference = $this->bindWorkspace->handle($workspace);
        $reference->assertAvailableForExecution();

        $contents = [...$files->read($paths), ...$files->readProtectedTest($testPath)];
        $snapshot = $files->snapshot($contents);
        $snapshot['preview'] = $this->preview->handle($files->path, $contents, 'task_creation');

        try {
            json_encode($source, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('SOURCE_INVALID: Source metadata must contain valid JSON values.', 0, $exception);
        }

        if (! $requireRedBaseline) {
            $source['red_baseline_required'] = false;
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
        } catch (QueryException $exception) {
            $reason = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();
            if (preg_match('/readonly database|read-only|disk I\/O error|database or disk is full|unable to open database file/i', $reason) !== 1) {
                throw $exception;
            }
            $database = DB::connection($exception->getConnectionName())->getDatabaseName();

            throw new RuntimeException('DATABASE_UNWRITABLE: Molly could not save the task in '.$database.' ('.$reason.'). Check free disk space and that the database file and its directory are writable.', 0, $exception);
        }

        $task = $this->journal->handle($task);
        $this->baseline->store($task, $contents);
        $this->lifecycle->handle($files->path, LifecycleEventType::Created, $task->id);

        return $task;
    }
}
