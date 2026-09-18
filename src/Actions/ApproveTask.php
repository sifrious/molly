<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Contracts\DisplayStatus;
use Sifrious\Molly\Contracts\LifecycleEventType;

class ApproveTask
{
    public function __construct(private RecordLifecycleEvent $lifecycle) {}

    /**
     * @return array{task_id: string, run_id: string|null, approved: bool, display_status: string}
     */
    public function handle(string $reference, bool $approved): array
    {
        if (! $approved) {
            throw new RuntimeException('APPROVAL_UNCONFIRMED: Molly records human approval only after --approve.');
        }

        $task = app(ShowTask::class)->handle($reference)
            ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        $log = $this->lifecycle->load($task->workspace);
        $status = $log->displayStatus($task->id);
        $run = $task->runs->last();
        if ($status === DisplayStatus::Approved) {
            return [
                'task_id' => $task->id,
                'run_id' => $run?->id,
                'approved' => true,
                'display_status' => $status->value,
            ];
        }
        if ($status !== DisplayStatus::AwaitingApproval) {
            throw new RuntimeException('APPROVAL_NOT_REQUESTED: Approve a task only after required checks pass and Molly asks for human approval.');
        }

        $this->lifecycle->handle(
            $task->workspace,
            LifecycleEventType::ApprovalResolved,
            $task->id,
            $run?->id,
            [
                'before_pull_request' => true,
                'before_merge' => true,
                'pull_request_opened' => false,
            ],
        );

        return [
            'task_id' => $task->id,
            'run_id' => $run?->id,
            'approved' => true,
            'display_status' => $this->lifecycle->load($task->workspace)->displayStatus($task->id)->value,
        ];
    }
}
