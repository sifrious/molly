<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\AuthoredTestBroken;
use Sifrious\Molly\ChoiceRequired;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;

class LockProtectedTest
{
    public function __construct(
        private RecordLifecycleEvent $lifecycle,
        private RefreshProjectJournal $journal,
        private RecordRedBaseline $redBaseline,
    ) {}

    /**
     * @param  list<string>  $implementationPaths
     * @return array{task_id: string, test_path: string, before_digest: string|null, after_digest: string, locked: bool, allow_test_edits: false, red_baseline: array<string, mixed>|null, paths: list<string>, scope_source: string}
     */
    public function handle(string $reference, bool $approved, array $implementationPaths = [], string $reason = 'Human approved the Pest test as the locked acceptance test.'): array
    {
        $task = app(ShowTask::class)->handle($reference);
        if (! $approved) {
            // Show the scope the approval would cover; nothing is saved.
            [$paths] = $task !== null && $task->allow_test_edits ? $this->scope($task, $implementationPaths) : [[]];

            throw new RuntimeException('TEST_LOCK_UNCONFIRMED: Molly locks the required Pest test only after --approve.'
                .($paths === [] ? '' : ' The implementation may then change: '.implode(', ', $paths).'.'));
        }

        $task ??= throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        if (! $task->allow_test_edits) {
            return $this->confirmLocked($task, $reason);
        }
        if ($task->status === 'running') {
            throw new RuntimeException('TEST_LOCK_RUNNING: Stop or finish the test-authoring run before locking the Pest test.');
        }

        $workspace = new Workspace($task->workspace);
        $after = $workspace->testDigest($task->test_path);
        if ($after === null) {
            throw new RuntimeException('PROTECTED_TEST_MISSING: Create the required Pest test before locking it.');
        }
        // Run the test as it is now. A test that cannot run is not locked; it goes back for a rewrite.
        $check = $this->redBaseline->check($task);
        if (($check['test_broken'] ?? false) === true) {
            throw new AuthoredTestBroken($task, $check, $task->authoringNextStep($check));
        }

        $before = is_string($task->test_digest) && $task->test_digest !== '' ? $task->test_digest : null;
        [$paths, $scopeSource] = $this->scope($task, $implementationPaths);
        if (array_diff($paths, [$task->test_path]) === []) {
            // Files derived from the story come first, then existing files.
            $choices = array_values(array_unique([...$this->scope($task, [])[0], ...$workspace->sourceFiles()]));
            $suggested = array_slice($choices, 0, 3);
            $command = 'php artisan molly:lock-test '.$task->reference().' --approve'
                .($suggested === [] ? ' --file=app/Example.php' : implode('', array_map(fn (string $path): string => ' --file='.$path, $suggested)));

            throw ChoiceRequired::fromList('SCOPE_REQUIRED: Task '.$task->reference().' has no implementation files. Name the files the implementation may change, for example: '.$command.'.', $choices, 'file', multiple: true, rerun: $command);
        }
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
        $baseline = $this->redBaseline->record($task->fresh(), $check);
        $this->journal->handle($task->fresh());

        return $this->result($task->id, $task->test_path, $before, $after, true, $baseline, $paths, $scopeSource);
    }

    /**
     * The implementation files a lock allows: the --file paths when given,
     * otherwise the task's own non-test files plus the files derived from its story.
     *
     * @param  list<string>  $implementationPaths
     * @return array{0: list<string>, 1: string}
     */
    private function scope(Task $task, array $implementationPaths): array
    {
        if ($implementationPaths !== []) {
            return [$implementationPaths, 'option'];
        }

        $own = array_values(array_filter($task->paths, fn (string $path): bool => $path !== $task->test_path));
        $derived = is_array($task->source['scope']['files'] ?? null) ? array_values(array_filter($task->source['scope']['files'], is_string(...))) : [];

        return [array_values(array_unique([...$own, ...$derived])), $derived === [] ? 'task' : 'derived'];
    }

    /**
     * A locked task keeps its digest. When its RED baseline is missing or
     * unusable, an approved lock records a new baseline, and re-locks the
     * test at its current digest if a human repaired the file.
     *
     * @return array{task_id: string, test_path: string, before_digest: string|null, after_digest: string, locked: bool, allow_test_edits: false, red_baseline: array<string, mixed>|null, paths: list<string>, scope_source: string}
     */
    private function confirmLocked(Task $task, string $reason): array
    {
        $digest = $task->test_digest;
        if (! is_string($digest) || $digest === '') {
            throw new RuntimeException('TEST_LOCK_NOT_AUTHORING: Lock a Pest test only after a test-authoring task writes it.');
        }
        $lock = $task->source['test_lock'] ?? null;
        $baseline = is_array($lock) ? ($lock['red_baseline'] ?? null) : null;
        if (! is_array($lock) || ($baseline['classification'] ?? null) === 'missing_behavior') {
            return $this->result($task->id, $task->test_path, $digest, $digest, false, $baseline, $task->paths, 'locked');
        }
        if ($task->status === 'running') {
            throw new RuntimeException('TEST_LOCK_RUNNING: Stop or finish the current run before recording a RED baseline.');
        }

        $current = (new Workspace($task->workspace))->testDigest($task->test_path);
        if ($current === null) {
            throw new RuntimeException('PROTECTED_TEST_MISSING: Create the required Pest test before locking it.');
        }
        if ($current !== $digest) {
            $source = $task->source;
            $source['test_lock'] = [
                ...$lock,
                'before_digest' => $digest,
                'after_digest' => $current,
                'reason' => $this->reason($reason),
                'approved_by' => 'human',
                'locked_at' => now()->toIso8601String(),
            ];
            unset($source['test_lock']['red_baseline']);
            $task->update(['test_digest' => $current, 'source' => $source, 'status' => 'pending']);
            $this->lifecycle->handle($task->workspace, LifecycleEventType::TestLocked, $task->id, $task->runs->last()?->id, $source['test_lock']);
        }

        $baseline = $this->redBaseline->handle($task->fresh());
        $this->journal->handle($task->fresh());

        return $this->result($task->id, $task->test_path, $digest, $current, $current !== $digest, $baseline, $task->paths, 'locked');
    }

    /**
     * @param  array<string, mixed>|null  $baseline
     * @param  list<string>  $paths
     * @return array{task_id: string, test_path: string, before_digest: string|null, after_digest: string, locked: bool, allow_test_edits: false, red_baseline: array<string, mixed>|null, paths: list<string>, scope_source: string}
     */
    private function result(string $taskId, string $testPath, ?string $before, string $after, bool $locked, ?array $baseline, array $paths, string $scopeSource): array
    {
        return [
            'task_id' => $taskId,
            'test_path' => $testPath,
            'before_digest' => $before,
            'after_digest' => $after,
            'locked' => $locked,
            'allow_test_edits' => false,
            'red_baseline' => $baseline,
            'paths' => $paths,
            'scope_source' => $scopeSource,
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
