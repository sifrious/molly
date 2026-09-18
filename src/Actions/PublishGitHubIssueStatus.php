<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;
use Sifrious\Molly\Models\Task;
use Throwable;

class PublishGitHubIssueStatus
{
    public function handle(string $reference, bool $approved, bool $closeIssue = false): array
    {
        if (! $approved) {
            throw new RuntimeException('GITHUB_WRITEBACK_UNAPPROVED: Molly posts a GitHub comment only after explicit approval.');
        }

        $task = app(ShowTask::class)->handle($reference)
            ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        $source = $task->source ?? [];
        $repository = $source['repository'] ?? null;
        $number = $source['issue_number'] ?? null;
        if (! is_string($repository) || ! is_int($number)) {
            throw new RuntimeException('GITHUB_SOURCE_MISSING: This task was not imported from a GitHub issue.');
        }

        $run = $task->runs->last();
        $body = $this->body($task, $run, $closeIssue);
        $digest = hash('sha256', $body);
        if (($source['github_comment_digest'] ?? null) === $digest && is_int($source['github_comment_id'] ?? null)) {
            return [
                'task_id' => $task->id,
                'comment_id' => $source['github_comment_id'],
                'comment_url' => $source['github_comment_url'] ?? null,
                'updated' => false,
            ];
        }

        $comment = is_int($source['github_comment_id'] ?? null)
            ? $this->request(['gh', 'api', '--hostname', 'github.com', 'repos/'.$repository.'/issues/comments/'.$source['github_comment_id'], '-X', 'PATCH', '--input', '-'], $body)
            : $this->request(['gh', 'api', '--hostname', 'github.com', 'repos/'.$repository.'/issues/'.$number.'/comments', '--input', '-'], $body);

        $id = $comment['id'] ?? null;
        $url = $comment['html_url'] ?? null;
        if (! is_int($id) || ! is_string($url) || $url === '') {
            throw new RuntimeException('GITHUB_COMMENT_INVALID: GitHub did not return a comment id and URL.');
        }

        $source['github_comment_id'] = $id;
        $source['github_comment_url'] = $url;
        $source['github_comment_digest'] = $digest;
        $source['github_commented_at'] = now()->toIso8601String();
        $task->update(['source' => $source]);

        return ['task_id' => $task->id, 'comment_id' => $id, 'comment_url' => $url, 'updated' => true];
    }

    /** @return array<string, mixed> */
    private function request(array $command, string $body): array
    {
        try {
            $result = Process::timeout(15)->input(json_encode(['body' => $body], JSON_THROW_ON_ERROR))->run($command);
        } catch (Throwable) {
            throw new RuntimeException('GITHUB_UNAVAILABLE: Molly could not write the issue comment. Check gh installation, login, and network access.');
        }
        if (! $result->successful()) {
            throw new RuntimeException('GITHUB_UNAVAILABLE: Molly could not write the issue comment. Check gh login and repository access.');
        }
        try {
            $payload = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('GITHUB_COMMENT_INVALID: GitHub did not return a valid comment.');
        }
        if (! is_array($payload)) {
            throw new RuntimeException('GITHUB_COMMENT_INVALID: GitHub did not return a valid comment.');
        }

        return $payload;
    }

    private function body(Task $task, mixed $run, bool $closeIssue): string
    {
        $verification = is_object($run) ? ($run->report['verification']['status'] ?? 'not run') : 'not run';
        $tarpit = is_object($run) ? ($run->report['review']['status'] ?? (isset($run->report['review']['checks']) ? 'recorded' : 'not run')) : 'not run';
        $lines = [
            '<!-- molly-task:'.$task->id.' -->',
            'Molly recorded task '.$task->id.' for this issue.',
            '',
            'Acceptance test: `'.$task->test_path.'`',
            'Approved digest: `'.($task->test_digest ?? 'none').'`',
            'Task status: '.$task->status,
            'Pest: '.$verification,
            'Tarpit: '.$tarpit,
            '',
            'Molly does not open or merge a pull request from this comment.',
        ];
        if ($closeIssue) {
            if ($task->status !== 'completed') {
                throw new RuntimeException('GITHUB_CLOSE_UNAVAILABLE: Closing language is allowed only after required checks pass.');
            }
            $lines[] = 'Closes #'.($task->source['issue_number'] ?? '').' after a human merges a pull request that meets the repository merge policy.';
        }

        return implode("\n", $lines)."\n";
    }
}
