<?php

namespace Sifrious\Molly\Console;

use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;

use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class TaskReport
{
    /**
     * @param  array{display_status: string, linked_pr: array{url: string, number: int|null, merge_sha: string|null}|null, issue_url: string|null}|null  $inspection
     */
    public function show(Task $task, ?array $inspection = null): void
    {
        note('Task '.$task->reference().' / '.$task->status);
        if ($inspection !== null) {
            note('Display status: '.$inspection['display_status']);
        }
        if ($task->nickname !== null) {
            note('Task ID: '.$task->id);
        }
        note($task->prompt);
        note('Workspace: '.$task->workspace);
        note('Required test: '.$task->test_path.($task->allow_test_edits ? ' (writable for this task)' : ' (protected)'));
        if ($task->test_digest) {
            note('Approved test digest: '.$task->test_digest);
        }
        $lock = is_array($task->source['test_lock'] ?? null) ? $task->source['test_lock'] : [];
        if (is_string($lock['after_digest'] ?? null)) {
            note('Locked test digest: '.$lock['after_digest']);
            if (is_string($lock['approved_by'] ?? null)) {
                note('Test lock approved by: '.$lock['approved_by']);
            }
        }
        if (($inspection['issue_url'] ?? null) !== null) {
            note('GitHub issue: '.$inspection['issue_url']);
        }
        if (($inspection['linked_pr']['url'] ?? null) !== null) {
            note('Recorded pull request: '.$inspection['linked_pr']['url']);
            if (($inspection['linked_pr']['merge_sha'] ?? null) !== null) {
                note('Recorded merge SHA: '.$inspection['linked_pr']['merge_sha']);
            }
        }
        table(['Allowed file'], array_map(fn (string $path): array => [$path], $task->paths));
        $journal = $task->journal_status ?? [];
        if (($journal['status'] ?? null) === 'written') {
            note('Project journal refreshed at '.$journal['checked_at'].'.');
            note('Journal: '.$journal['journal_path']);
            note('Glossary: '.$journal['glossary_path']);
        } elseif (($journal['status'] ?? null) === 'unavailable') {
            warning('Project journal unavailable. '.$journal['reason']);
            note('The journal warning does not change the task result.');
        } else {
            note('Project journal has not been refreshed for this task.');
        }
        note('Refresh the project journal with php artisan molly:journal '.$task->reference().' --project.');
        if ($task->status === 'running' && $task->stop_requested_at !== null) {
            note('Stop requested. Molly will stop at the next execution boundary. An active model request or test process may finish first.');
        }
        if ($task->relationLoaded('runs')) {
            if ($task->runs->isEmpty()) {
                note('No runs yet. Start this task with php artisan molly:start '.$task->reference().'.');
            } else {
                table(['Run', 'Status'], $task->runs->map(fn (Run $run): array => [$run->id, $run->status])->all());
                note('Read a run report with php artisan molly:show RUN_ID.');
            }
        }
    }
}
