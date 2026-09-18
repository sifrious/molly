<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Contracts\HandoffEnvelope;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Models\Task;

class AcceptHandoff
{
    public function __construct(private RecordLifecycleEvent $lifecycle) {}

    public function handle(HandoffEnvelope $handoff, string $reference, string $recipientWorkspaceId): HandoffEnvelope
    {
        $task = app(ShowTask::class)->handle($reference)
            ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        $this->assertUnchanged($handoff, $task, $recipientWorkspaceId);
        $this->lifecycle->handle(
            $task->workspace,
            LifecycleEventType::Recovered,
            $task->id,
            $handoff->sourceRunId,
            [
                'handoff_id' => $handoff->handoffId,
                'sender_workspace_id' => $handoff->senderWorkspaceId,
                'requested_next_action' => $handoff->requestedNextAction,
            ],
            $this->eventId($handoff->handoffId),
        );

        return $handoff;
    }

    private function assertUnchanged(HandoffEnvelope $handoff, Task $task, string $recipientWorkspaceId): void
    {
        if ($handoff->sourceTaskId !== $task->id) {
            throw new RuntimeException('HANDOFF_TASK_MISMATCH: The envelope does not name this task.');
        }
        if ($handoff->recipientWorkspaceId !== $recipientWorkspaceId) {
            throw new RuntimeException('HANDOFF_WORKSPACE_MISMATCH: The envelope does not name this Bloom workspace.');
        }
        if ($task->allow_test_edits) {
            throw new RuntimeException('HANDOFF_SCOPE_WIDENED: The recipient cannot edit the protected Pest test.');
        }
        if ($task->paths !== $handoff->allowedPaths) {
            throw new RuntimeException('HANDOFF_SCOPE_WIDENED: The recipient cannot widen the allowed file scope.');
        }
        if ($handoff->protectedTests !== [$task->test_path]) {
            throw new RuntimeException('HANDOFF_SCOPE_WIDENED: The recipient cannot change the protected test.');
        }
        if (in_array($handoff->requestedNextAction, ['merge', 'open_pull_request'], true)) {
            throw new RuntimeException('HANDOFF_PRIVILEGE: The recipient cannot merge or open a pull request.');
        }
    }

    private function eventId(string $handoffId): string
    {
        $hash = md5('molly.handoff.recovered.'.$handoffId);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-4'.substr($hash, 13, 3).'-8'.substr($hash, 17, 3).'-'.substr($hash, 20, 12);
    }
}
