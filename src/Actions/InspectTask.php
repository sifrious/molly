<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Contracts\LifecycleLog;
use Sifrious\Molly\Models\Task;

class InspectTask
{
    public function __construct(private RecordLifecycleEvent $lifecycle) {}

    /**
     * @return array{
     *     task: Task,
     *     display_status: string,
     *     linked_pr: array{url: string, number: int|null, merge_sha: string|null}|null,
     *     issue_url: string|null
     * }
     */
    public function handle(Task $task): array
    {
        $log = $this->load($task->workspace);

        return [
            'task' => $task,
            'display_status' => $log->displayStatus($task->id)->value,
            'linked_pr' => $this->linkedPr($task, $log),
            'issue_url' => $this->httpsUrl($task->source['issue_url'] ?? null),
        ];
    }

    private function load(?string $workspace): LifecycleLog
    {
        if (! is_string($workspace) || $workspace === '' || ! is_dir($workspace)) {
            return new LifecycleLog;
        }

        return $this->lifecycle->load($workspace);
    }

    /**
     * @return array{url: string, number: int|null, merge_sha: string|null}|null
     */
    private function linkedPr(Task $task, LifecycleLog $log): ?array
    {
        $opened = $log->latestOf($task->id, LifecycleEventType::PullRequestOpened);
        $merged = $log->latestOf($task->id, LifecycleEventType::Merged);
        $source = is_array($task->source['linked_pr'] ?? null) ? $task->source['linked_pr'] : [];
        $url = $this->httpsUrl($opened?->payload['url'] ?? ($source['url'] ?? null));
        if ($url === null) {
            return null;
        }

        $number = $opened?->payload['number'] ?? ($source['number'] ?? null);
        $sha = $merged?->payload['sha'] ?? ($source['merge_sha'] ?? null);

        return [
            'url' => $url,
            'number' => is_int($number) ? $number : null,
            'merge_sha' => is_string($sha) && preg_match('/\A[0-9a-f]{40}\z/', strtolower($sha)) === 1 ? strtolower($sha) : null,
        ];
    }

    private function httpsUrl(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('~\Ahttps://github\.com/[A-Za-z0-9][A-Za-z0-9-]*/[A-Za-z0-9_.-]+/(?:issues|pull)/[1-9][0-9]*\z~D', $value)) {
            return null;
        }

        return $value;
    }
}
