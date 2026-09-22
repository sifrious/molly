<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Str;
use RuntimeException;
use Sifrious\Molly\Contracts\LifecycleEvent;
use Sifrious\Molly\Journal\JournalRenderer;
use Sifrious\Molly\Journal\JournalWriter;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;
use Throwable;

class ExportTaskJournal
{
    public function __construct(
        private ShowTask $showTask,
        private RecordLifecycleEvent $lifecycleEvents,
        private JournalRenderer $journalRenderer,
        private JournalWriter $journalWriter,
    ) {}

    /** @return array{path: string, task_id: string, attempt_count: int} */
    public function handle(string $reference): array
    {
        $task = $this->showTask->handle($reference);
        if ($task === null) {
            throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        }
        if (! Str::isUuid($task->id)) {
            throw new RuntimeException('JOURNAL_PATH_INVALID: A task journal requires a valid task UUID.');
        }

        $root = (new Workspace($task->workspace))->path;
        $directory = $root.'/.molly/journal';
        $path = $directory.'/'.$task->id.'.md';
        $markdown = $this->journalRenderer->renderTask($this->taskPayload($task));
        $this->prepareDirectory($root);
        $this->write($root, $path, $markdown);

        return ['path' => $path, 'task_id' => $task->id, 'attempt_count' => $task->runs->count()];
    }

    /** @return array{journal_path: string, glossary_path: string, task_count: int, attempt_count: int} */
    public function forWorkspace(string $workspace): array
    {
        $root = (new Workspace($workspace))->path;
        $tasks = Task::where('workspace', $root)->with('runs')->get();
        $entries = [];
        foreach ($tasks as $task) {
            $entries[] = ['model' => $task, 'task' => $task, 'number' => 0];
            foreach ($task->runs as $index => $run) {
                $entries[] = ['model' => $run, 'task' => $task, 'number' => $index + 1];
            }
        }
        usort($entries, fn (array $left, array $right): int => [
            $left['model']->created_at?->toISOString(), $left['number'] > 0, $left['model']->id,
        ] <=> [
            $right['model']->created_at?->toISOString(), $right['number'] > 0, $right['model']->id,
        ]);

        $payloads = [];
        foreach ($entries as $entry) {
            $payloads[] = $this->projectEntryPayload($entry['task'], $entry['model'], $entry['number']);
        }

        $journalPath = $root.'/.molly/JOURNAL.md';
        $glossaryPath = $root.'/.molly/GLOSSARY.md';
        $this->prepareDirectory($root);
        $this->updateGlossary($root, $glossaryPath);
        $this->write($root, $journalPath, $this->journalRenderer->renderProject($payloads));

        return ['journal_path' => $journalPath, 'glossary_path' => $glossaryPath, 'task_count' => $tasks->count(), 'attempt_count' => count($entries) - $tasks->count()];
    }

    /** @return array<string, mixed> */
    private function projectEntryPayload(Task $task, Task|Run $record, int $number): array
    {
        if ($record instanceof Run) {
            return [
                'kind' => 'attempt',
                'number' => $number,
                'task_id' => $task->id,
                'nickname' => $task->nickname ?? 'Unnamed',
                'run' => $this->runPayload($record),
            ];
        }

        return [
            'kind' => 'task',
            'id' => $task->id,
            'nickname' => $task->nickname ?? 'Unnamed',
            'status' => $task->status,
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            'test_path' => $task->test_path,
            'allow_test_edits' => (bool) $task->allow_test_edits,
            'test_digest' => $task->test_digest ?? 'none',
            'test_lock_lines' => $this->testLockLines($task),
            'issue_lines' => $this->issueLines($task),
            'lifecycle_lines' => $this->lifecycleSummary($task),
            'prompt' => $task->prompt,
        ];
    }

    /** @return array<string, mixed> */
    private function taskPayload(Task $task): array
    {
        return [
            'id' => $task->id,
            'nickname' => $task->nickname ?? 'Unnamed',
            'status' => $task->status,
            'created_at' => $task->created_at?->toIso8601String(),
            'updated_at' => $task->updated_at?->toIso8601String(),
            'stop_requested_at' => $task->stop_requested_at?->toIso8601String() ?? 'No',
            'test_path' => $task->test_path,
            'allow_test_edits' => (bool) $task->allow_test_edits,
            'test_digest' => $task->test_digest ?? 'none',
            'test_lock_lines' => $this->testLockLines($task),
            'issue_lines' => $this->issueLines($task),
            'lifecycle_lines' => $this->lifecycleSummary($task),
            'prompt' => $task->prompt,
            'paths' => array_values($task->paths ?? []),
            'runs' => $task->runs->map(fn (Run $run): array => $this->runPayload($run))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function runPayload(Run $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'created_at' => $run->created_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
            'report' => is_array($run->report) ? $run->report : [],
        ];
    }

    /** @return list<string> */
    private function testLockLines(Task $task): array
    {
        $lock = $task->source['test_lock'] ?? null;
        if (! is_array($lock) || ! is_string($lock['after_digest'] ?? null)) {
            return [];
        }

        $escape = fn (mixed $value): string => $this->journalRenderer->escape($value);
        $lines = ['- Locked test digest: '.$escape($lock['after_digest'])];
        if (is_string($lock['before_digest'] ?? null)) {
            $lines[] = '- Previous test digest: '.$escape($lock['before_digest']);
        }
        if (is_string($lock['approved_by'] ?? null)) {
            $lines[] = '- Test lock approved by: '.$escape($lock['approved_by']);
        }
        if (is_string($lock['reason'] ?? null)) {
            $lines[] = '- Test lock reason: '.$escape($lock['reason']);
        }

        return $lines;
    }

    /** @return list<string> */
    private function issueLines(Task $task): array
    {
        $source = $task->source ?? [];
        if (! is_string($source['issue_url'] ?? null)) {
            return [];
        }

        $escape = fn (mixed $value): string => $this->journalRenderer->escape($value);
        $lines = ['- GitHub issue: '.$escape($source['issue_url'])];
        if (is_string($source['issue_digest'] ?? null)) {
            $lines[] = '- Issue digest: '.$escape($source['issue_digest']);
        }
        if (is_string($source['github_comment_url'] ?? null)) {
            $lines[] = '- GitHub comment: '.$escape($source['github_comment_url']);
        }
        $linked = $source['linked_pr'] ?? null;
        if (is_array($linked) && is_string($linked['url'] ?? null)) {
            $lines[] = '- Recorded pull request: '.$escape($linked['url']);
            if (is_string($linked['merge_sha'] ?? null)) {
                $lines[] = '- Recorded merge SHA: '.$escape($linked['merge_sha']);
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private function lifecycleSummary(Task $task): array
    {
        $log = $this->lifecycleEvents->load($task->workspace);
        $events = $log->events($task->id);
        if ($events === []) {
            return [];
        }

        $escape = fn (mixed $value): string => $this->journalRenderer->escape($value);
        $lines = ['- Display status: '.$escape($log->displayStatus($task->id)->value)];
        foreach ($events as $event) {
            $lines[] = '- Lifecycle: '.$escape($this->lifecycleLine($event));
        }

        return $lines;
    }

    private function lifecycleLine(LifecycleEvent $event): string
    {
        $line = $event->type;
        $url = $event->payload['url'] ?? null;
        $sha = $event->payload['sha'] ?? ($event->payload['merge_sha'] ?? null);
        if (is_string($url) && $url !== '') {
            $line .= ' '.$url;
        }
        if (is_string($sha) && $sha !== '') {
            $line .= ' '.$sha;
        }

        return $line;
    }

    private function updateGlossary(string $root, string $path): void
    {
        $existing = $this->readExisting($path);
        $contents = $this->journalRenderer->replaceManagedGlossary($existing ?? '');
        if ($contents !== ($existing ?? '')) {
            $this->journalWriter->replaceFile($path, $contents, $existing === null ? false : hash('sha256', $existing));
        }
    }

    private function prepareDirectory(string $root): void
    {
        $this->journalWriter->prepareMollyDirectory($root);
    }

    private function readExisting(string $path): ?string
    {
        $this->journalWriter->validateFile($path);
        try {
            if (! is_file($path)) {
                return null;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('JOURNAL_WRITE_FAILED: Molly could not read an existing journal support file.');
            }

            return $contents;
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'JOURNAL_')) {
                throw $exception;
            }
            throw new RuntimeException('JOURNAL_WRITE_FAILED: Molly could not read an existing journal support file.', previous: $exception);
        }
    }

    private function write(string $root, string $path, string $markdown, string|false|null $expectedHash = null): void
    {
        $this->journalWriter->ensureDirectory($root.'/.molly');
        $this->journalWriter->replaceFile($path, $markdown, $expectedHash);
    }
}
