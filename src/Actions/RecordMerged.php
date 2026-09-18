<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Contracts\LifecycleEventType;

class RecordMerged
{
    public function __construct(private RecordLifecycleEvent $lifecycle) {}

    /**
     * @return array{task_id: string, run_id: string|null, pull_request_url: string, merge_sha: string, recorded: bool, merged: false, display_status: string}
     */
    public function handle(string $reference, bool $approved, string $sha): array
    {
        if (! $approved) {
            throw new RuntimeException('MERGE_RECORD_UNCONFIRMED: Molly records a merge only after --approve. It still does not merge the pull request.');
        }

        $sha = $this->parseSha($sha);
        $task = app(ShowTask::class)->handle($reference)
            ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        $log = $this->lifecycle->load($task->workspace);
        $status = $log->displayStatus($task->id);
        $run = $task->runs->last();
        $opened = $log->latestOf($task->id, LifecycleEventType::PullRequestOpened);
        if ($opened === null || ! is_string($opened->payload['url'] ?? null)) {
            throw new RuntimeException('MERGE_RECORD_PR_MISSING: Record the opened pull request before recording a merge.');
        }

        $existing = $log->latestOf($task->id, LifecycleEventType::Merged);
        if ($existing !== null) {
            $recordedSha = $existing->payload['sha'] ?? null;
            if ($recordedSha !== $sha) {
                throw new RuntimeException('MERGE_RECORD_CONFLICT: This task already records a different merge SHA.');
            }

            return $this->result($task->id, $run?->id, $opened->payload['url'], $sha, false, $status->value);
        }
        if ($log->latestOf($task->id, LifecycleEventType::ApprovalResolved) === null) {
            throw new RuntimeException('MERGE_RECORD_NOT_APPROVED: Record a merge only after human approval and an opened pull request.');
        }

        $this->lifecycle->handle(
            $task->workspace,
            LifecycleEventType::Merged,
            $task->id,
            $run?->id,
            [
                'url' => $opened->payload['url'],
                'sha' => $sha,
                'merged' => false,
            ],
        );

        $source = $task->source ?? [];
        $linked = is_array($source['linked_pr'] ?? null) ? $source['linked_pr'] : [];
        $linked['url'] = $opened->payload['url'];
        $linked['merge_sha'] = $sha;
        $linked['merged_at'] = now()->toIso8601String();
        $source['linked_pr'] = $linked;
        $task->update(['source' => $source]);

        return $this->result(
            $task->id,
            $run?->id,
            $opened->payload['url'],
            $sha,
            true,
            $this->lifecycle->load($task->workspace)->displayStatus($task->id)->value,
        );
    }

    private function parseSha(string $sha): string
    {
        $sha = strtolower($sha);
        if (! preg_match('/\A[0-9a-f]{40}\z/', $sha)) {
            throw new RuntimeException('MERGE_SHA_INVALID: Use a 40-character hexadecimal merge commit SHA.');
        }

        return $sha;
    }

    /**
     * @return array{task_id: string, run_id: string|null, pull_request_url: string, merge_sha: string, recorded: bool, merged: false, display_status: string}
     */
    private function result(string $taskId, ?string $runId, string $url, string $sha, bool $recorded, string $status): array
    {
        return [
            'task_id' => $taskId,
            'run_id' => $runId,
            'pull_request_url' => $url,
            'merge_sha' => $sha,
            'recorded' => $recorded,
            'merged' => false,
            'display_status' => $status,
        ];
    }
}
