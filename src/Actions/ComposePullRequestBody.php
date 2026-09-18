<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;

class ComposePullRequestBody
{
    public function handle(string $reference, bool $closeIssue = false): array
    {
        $task = app(ShowTask::class)->handle($reference)
            ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        $source = $task->source ?? [];
        $issueUrl = $source['issue_url'] ?? null;
        $run = $task->runs->last();
        $body = $this->markdown($task, $run, is_string($issueUrl) ? $issueUrl : null, $closeIssue);

        return [
            'task_id' => $task->id,
            'issue_url' => is_string($issueUrl) ? $issueUrl : null,
            'body' => $body,
        ];
    }

    private function markdown(Task $task, ?Run $run, ?string $issueUrl, bool $closeIssue): string
    {
        $report = $run?->report ?? [];
        $outcomes = $report['verification_outcomes'] ?? [];
        $lines = [
            '## Molly evidence',
            '',
            $issueUrl === null ? 'This change is not linked to a GitHub issue.' : 'Issue: '.$issueUrl,
            'Acceptance test: `'.$task->test_path.'`',
            'Approved digest: `'.($task->test_digest ?? 'none').'`',
            'Task: '.$task->id,
            'Attempts: '.$task->runs->count(),
        ];
        if ($run !== null) {
            $lines[] = 'Latest run: '.$run->id.' / '.$run->status;
        }
        foreach (['pest' => 'Pest', 'tarpit' => 'Tarpit', 'parallel_join' => 'Parallel join'] as $name => $label) {
            if (! isset($outcomes[$name])) {
                continue;
            }
            $lines[] = $label.': '.($outcomes[$name]['state'] ?? 'NOT_RUN').' ('.($outcomes[$name]['policy'] ?? 'unknown').', '.($outcomes[$name]['failure_action'] ?? 'unknown').')';
        }
        $blockers = $report['completion_blockers'] ?? [];
        $lines[] = $blockers === [] ? 'Required blockers: none' : 'Required blockers: '.implode(', ', $blockers);
        $lines[] = '';
        $lines[] = 'This body omits prompts, secrets, local paths, and private source.';
        $lines[] = 'A human must approve opening or merging a pull request. Molly does not merge.';
        if ($closeIssue) {
            $issueNumber = $task->source['issue_number'] ?? null;
            if ($task->status !== 'completed' || ! is_int($issueNumber)) {
                throw new RuntimeException('GITHUB_CLOSE_UNAVAILABLE: Closing language is allowed only after required checks pass for an imported issue.');
            }
            $lines[] = '';
            $lines[] = 'Closes #'.$issueNumber;
        }

        return implode("\n", $lines)."\n";
    }
}
