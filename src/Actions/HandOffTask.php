<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Str;
use RuntimeException;
use Sifrious\Molly\Contracts\HandoffEnvelope;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Journal\JournalWriter;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Redaction\SecretRedactor;
use Sifrious\Molly\Workspace;
use Sifrious\Molly\Workspace\Directory;

class HandOffTask
{
    public function __construct(
        private RecordLifecycleEvent $lifecycle,
        private JournalWriter $journal,
        private SecretRedactor $redactor,
    ) {}

    /**
     * Save the envelope under .molly/handoffs with owner-only permissions, then record the
     * handoff. A failed write records nothing.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{envelope: HandoffEnvelope, path: string}
     */
    public function handle(
        string $reference,
        bool $approved,
        string $senderWorkspaceId,
        string $recipientWorkspaceId,
        string $requestedNextAction,
        string $boundedContext,
        array $overrides = [],
    ): array {
        if (! $approved) {
            throw new RuntimeException('HANDOFF_UNCONFIRMED: Molly hands a task to another workspace only after --approve. It still does not merge or open a pull request.');
        }

        $task = app(ShowTask::class)->handle($reference)
            ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        if ($task->allow_test_edits) {
            throw new RuntimeException('HANDOFF_TEST_WRITABLE: Hand off implementation only after the required Pest test is locked.');
        }
        if (! is_string($task->test_digest) || $task->test_digest === '') {
            throw new RuntimeException('PROTECTED_TEST_MISSING: Hand off implementation only after the required Pest test is approved.');
        }
        if (in_array($requestedNextAction, ['merge', 'open_pull_request'], true)) {
            throw new RuntimeException('HANDOFF_PRIVILEGE: A handoff cannot authorize merge or pull request opening.');
        }

        $run = $task->runs->last();
        $sourceRunId = is_string($overrides['source_run_id'] ?? null) ? $overrides['source_run_id'] : $run?->id;
        if ($sourceRunId === null) {
            throw new RuntimeException('HANDOFF_RUN_MISSING: Hand off after the source workspace has a saved attempt.');
        }
        $envelope = new HandoffEnvelope(
            $overrides['handoff_id'] ?? (string) Str::uuid(),
            $task->id,
            $sourceRunId,
            $senderWorkspaceId,
            $recipientWorkspaceId,
            $this->redactor->text($boundedContext, $task->workspace),
            $task->paths,
            [$task->test_path],
            $this->diagnostics($task, $run),
            array_values($overrides['artifacts'] ?? []),
            $requestedNextAction,
            'molly.task:'.$task->id.'; sender:'.$senderWorkspaceId.'; recipient:'.$recipientWorkspaceId,
        );
        $path = $this->write($task, $envelope);

        $this->lifecycle->handle(
            $task->workspace,
            LifecycleEventType::HandedOff,
            $task->id,
            $run?->id,
            [
                'handoff_id' => $envelope->handoffId,
                'recipient_workspace_id' => $envelope->recipientWorkspaceId,
                'requested_next_action' => $envelope->requestedNextAction,
            ],
            $envelope->handoffId,
        );

        return ['envelope' => $envelope, 'path' => $path];
    }

    private function write(Task $task, HandoffEnvelope $envelope): string
    {
        $root = (new Workspace($task->workspace))->path;
        $path = Directory::molly($root, 'handoffs/'.$envelope->handoffId.'.json');
        $this->journal->prepareMollyDirectory($root);
        $this->journal->replaceFile($path, json_encode($envelope->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");

        return $path;
    }

    /**
     * @return list<string>
     */
    private function diagnostics(Task $task, mixed $run): array
    {
        $lines = ['task_status:'.$task->status];
        if (! is_object($run)) {
            return $lines;
        }
        $report = $run->report ?? [];
        if (is_string($report['verification']['status'] ?? null)) {
            $lines[] = 'pest:'.$report['verification']['status'];
        }
        if (is_string($report['verification']['reason'] ?? null)) {
            $lines[] = 'pest_reason:'.$report['verification']['reason'];
        }
        foreach (array_slice($report['review']['findings'] ?? [], 0, 3) as $finding) {
            if (! is_array($finding) || ! is_string($finding['problem'] ?? null)) {
                continue;
            }
            $lines[] = 'finding:'.mb_strcut($finding['problem'], 0, 192, 'UTF-8');
        }

        return $lines;
    }
}
