<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Contracts\DisplayStatus;
use Sifrious\Molly\Contracts\LifecycleEventType;

class RecordPullRequestOpened
{
    public function __construct(private RecordLifecycleEvent $lifecycle) {}

    /**
     * @return array{task_id: string, run_id: string|null, pull_request_url: string, pull_request_number: int, recorded: bool, opened: false, display_status: string}
     */
    public function handle(string $reference, bool $approved, string $url): array
    {
        if (! $approved) {
            throw new RuntimeException('PR_RECORD_UNCONFIRMED: Molly records an opened pull request only after --approve. It still does not open the pull request.');
        }

        $parsed = $this->parse($url);
        $task = app(ShowTask::class)->handle($reference)
            ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        $sourceRepository = $task->source['repository'] ?? null;
        if (is_string($sourceRepository) && $sourceRepository !== $parsed['repository']) {
            throw new RuntimeException('PR_RECORD_REPOSITORY: The pull request must belong to the imported GitHub repository.');
        }

        $log = $this->lifecycle->load($task->workspace);
        $status = $log->displayStatus($task->id);
        $run = $task->runs->last();
        $existing = $log->latestOf($task->id, LifecycleEventType::PullRequestOpened);
        if ($existing !== null) {
            $recordedUrl = $existing->payload['url'] ?? null;
            if ($recordedUrl !== $parsed['url']) {
                throw new RuntimeException('PR_RECORD_CONFLICT: This task already records a different pull request.');
            }

            return $this->result($task->id, $run?->id, $parsed, false, $status->value);
        }
        if ($status === DisplayStatus::Merged) {
            throw new RuntimeException('PR_RECORD_MERGED: This task already records a merge.');
        }
        if ($status !== DisplayStatus::Approved) {
            throw new RuntimeException('PR_RECORD_NOT_APPROVED: Record a pull request only after molly:approve --approve.');
        }

        $this->lifecycle->handle(
            $task->workspace,
            LifecycleEventType::PullRequestOpened,
            $task->id,
            $run?->id,
            [
                'url' => $parsed['url'],
                'number' => $parsed['number'],
                'repository' => $parsed['repository'],
                'opened' => false,
            ],
        );

        $source = $task->source ?? [];
        $source['linked_pr'] = [
            'url' => $parsed['url'],
            'number' => $parsed['number'],
            'repository' => $parsed['repository'],
            'recorded_at' => now()->toIso8601String(),
        ];
        $task->update(['source' => $source]);

        return $this->result(
            $task->id,
            $run?->id,
            $parsed,
            true,
            $this->lifecycle->load($task->workspace)->displayStatus($task->id)->value,
        );
    }

    /**
     * @return array{url: string, number: int, repository: string}
     */
    private function parse(string $url): array
    {
        if (! preg_match('~\Ahttps://github\.com/([A-Za-z0-9][A-Za-z0-9-]*)/([A-Za-z0-9_.-]+)/pull/([1-9][0-9]*)\z~D', $url, $matches)
            || in_array($matches[2], ['.', '..'], true)
            || filter_var($matches[3], FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException('PR_URL_INVALID: Use an HTTPS github.com pull request URL without a query or fragment.');
        }

        return [
            'url' => $url,
            'number' => (int) $matches[3],
            'repository' => $matches[1].'/'.$matches[2],
        ];
    }

    /**
     * @param  array{url: string, number: int, repository: string}  $parsed
     * @return array{task_id: string, run_id: string|null, pull_request_url: string, pull_request_number: int, recorded: bool, opened: false, display_status: string}
     */
    private function result(string $taskId, ?string $runId, array $parsed, bool $recorded, string $status): array
    {
        return [
            'task_id' => $taskId,
            'run_id' => $runId,
            'pull_request_url' => $parsed['url'],
            'pull_request_number' => $parsed['number'],
            'recorded' => $recorded,
            'opened' => false,
            'display_status' => $status,
        ];
    }
}
