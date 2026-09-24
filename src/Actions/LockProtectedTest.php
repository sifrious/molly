<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Workspace;

class LockProtectedTest
{
    public function __construct(
        private RecordLifecycleEvent $lifecycle,
        private RefreshProjectJournal $journal,
    ) {}

    /**
     * @param  list<string>  $implementationPaths
     * @return array{task_id: string, test_path: string, before_digest: string|null, after_digest: string, locked: bool, allow_test_edits: false}
     */
    public function handle(string $reference, bool $approved, array $implementationPaths = [], string $reason = 'Human approved the Pest test as the locked acceptance test.'): array
    {
        if (! $approved) {
            throw new RuntimeException('TEST_LOCK_UNCONFIRMED: Molly locks the required Pest test only after --approve.');
        }

        $task = app(ShowTask::class)->handle($reference)
            ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        if (! $task->allow_test_edits) {
            $digest = $task->test_digest;
            if (is_string($digest) && $digest !== '') {
                return $this->result($task->id, $task->test_path, $digest, $digest, false);
            }

            throw new RuntimeException('TEST_LOCK_NOT_AUTHORING: Lock a Pest test only after a test-authoring task writes it.');
        }
        if ($task->status === 'running') {
            throw new RuntimeException('TEST_LOCK_RUNNING: Stop or finish the test-authoring run before locking the Pest test.');
        }

        $workspace = new Workspace($task->workspace);
        $after = $workspace->testDigest($task->test_path);
        if ($after === null) {
            throw new RuntimeException('PROTECTED_TEST_MISSING: Create the required Pest test before locking it.');
        }

        $before = is_string($task->test_digest) && $task->test_digest !== '' ? $task->test_digest : null;
        $paths = $implementationPaths === []
            ? array_values(array_filter($task->paths, fn (string $path): bool => $path !== $task->test_path))
            : $implementationPaths;
        $paths = $workspace->taskPaths($paths, $task->test_path, false);

        $source = $task->source ?? [];
        $source['test_lock'] = [
            'before_digest' => $before,
            'after_digest' => $after,
            'reason' => $this->reason($reason),
            'approved_by' => 'human',
            'locked_at' => now()->toIso8601String(),
            // Authoring attempts stay recorded on the task but are not charged to the implementation scope.
            'runs_before' => $task->runs()->count(),
        ];
        $task->update([
            'allow_test_edits' => false,
            'test_digest' => $after,
            'paths' => $paths,
            'status' => 'pending',
            'source' => $source,
        ]);
        $this->lifecycle->handle($task->workspace, LifecycleEventType::TestLocked, $task->id, $task->runs->last()?->id, $source['test_lock']);
        $this->journal->handle($task->fresh());

        return $this->result($task->id, $task->test_path, $before, $after, true);
    }

    /**
     * @return array{task_id: string, test_path: string, before_digest: string|null, after_digest: string, locked: bool, allow_test_edits: false}
     */
    private function result(string $taskId, string $testPath, ?string $before, string $after, bool $locked): array
    {
        return [
            'task_id' => $taskId,
            'test_path' => $testPath,
            'before_digest' => $before,
            'after_digest' => $after,
            'locked' => $locked,
            'allow_test_edits' => false,
        ];
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 512) {
            throw new RuntimeException('TEST_LOCK_REASON_INVALID: Explain the lock in 1 to 512 bytes.');
        }

        return $reason;
    }
}
