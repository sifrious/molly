<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\File;
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
        $markdown = $this->render($task);
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

        $lines = ['# Project journal', '',
            'Saved tasks and attempts appear in creation order. Each entry shows its current saved status and update time. This is not a complete history of lifecycle transitions.', '',
            'The host database remains the source of truth. Export again to refresh this file.', '',
        ];
        foreach ($entries as $entry) {
            $lines = [...$lines, ...$this->projectEntry($entry['task'], $entry['model'], $entry['number'])];
        }
        if ($entries === []) {
            $lines[] = 'No saved tasks or attempts in this workspace.';
        }
        $journalPath = $root.'/.molly/JOURNAL.md';
        $glossaryPath = $root.'/.molly/GLOSSARY.md';
        $this->prepareDirectory($root);
        $this->updateGlossary($root, $glossaryPath);
        $this->write($root, $journalPath, implode("\n", $lines)."\n");

        return ['journal_path' => $journalPath, 'glossary_path' => $glossaryPath, 'task_count' => $tasks->count(), 'attempt_count' => count($entries) - $tasks->count()];
    }

    /** @return list<string> */
    private function projectEntry(Task $task, Task|Run $record, int $number): array
    {
        $identity = ['- Task UUID: '.$this->escape($task->id), '- Nickname: '.$this->escape($task->nickname ?? 'Unnamed')];
        if ($record instanceof Run) {
            $lines = $this->attempt($record, $number);
            $lines[0] = '## Attempt '.$number;

            return [...array_slice($lines, 0, 2), ...$identity, ...array_slice($lines, 2)];
        }

        return ['## Task created', '', ...$identity,
            '- Status: '.$this->escape($task->status),
            '- Created: '.$this->escape($task->created_at?->toIso8601String()),
            '- Updated: '.$this->escape($task->updated_at?->toIso8601String()),
            '- Required test: '.$this->escape($task->test_path),
            '- Test protection: '.($task->allow_test_edits ? 'writable for this task' : 'protected'),
            '- Approved test digest: '.$this->escape($task->test_digest ?? 'none'),
            ...$this->testLockLines($task),
            ...$this->issueLines($task),
            ...$this->lifecycleSummary($task), '',
            $this->quote($task->prompt), '',
        ];
    }

    /** @return list<string> */
    private function testLockLines(Task $task): array
    {
        $lock = $task->source['test_lock'] ?? null;
        if (! is_array($lock) || ! is_string($lock['after_digest'] ?? null)) {
            return [];
        }

        $lines = ['- Locked test digest: '.$this->escape($lock['after_digest'])];
        if (is_string($lock['before_digest'] ?? null)) {
            $lines[] = '- Previous test digest: '.$this->escape($lock['before_digest']);
        }
        if (is_string($lock['approved_by'] ?? null)) {
            $lines[] = '- Test lock approved by: '.$this->escape($lock['approved_by']);
        }
        if (is_string($lock['reason'] ?? null)) {
            $lines[] = '- Test lock reason: '.$this->escape($lock['reason']);
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

        $lines = ['- GitHub issue: '.$this->escape($source['issue_url'])];
        if (is_string($source['issue_digest'] ?? null)) {
            $lines[] = '- Issue digest: '.$this->escape($source['issue_digest']);
        }
        if (is_string($source['github_comment_url'] ?? null)) {
            $lines[] = '- GitHub comment: '.$this->escape($source['github_comment_url']);
        }
        $linked = $source['linked_pr'] ?? null;
        if (is_array($linked) && is_string($linked['url'] ?? null)) {
            $lines[] = '- Recorded pull request: '.$this->escape($linked['url']);
            if (is_string($linked['merge_sha'] ?? null)) {
                $lines[] = '- Recorded merge SHA: '.$this->escape($linked['merge_sha']);
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

        $lines = ['- Display status: '.$this->escape($log->displayStatus($task->id)->value)];
        foreach ($events as $event) {
            $lines[] = '- Lifecycle: '.$this->escape($this->lifecycleLine($event));
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

    private function glossary(): string
    {
        return <<<'MARKDOWN'
## Molly terms

Molly updates this marked section with the project journal. Add project-specific definitions outside the section.

- Task: A saved request, editable file scope, and required Pest test. The UUID stays the same when its nickname changes. The required test is protected unless the task explicitly allows test edits.
- Nickname: An optional readable task reference. Commands also accept the task UUID.
- Attempt: One saved run linked to a task. Retrying creates another attempt without replacing earlier evidence.
- Verification: The recorded Pest result and counts. A skipped or missing check is not a pass.
- Tarpit review: Seven checks, A through G, with evidence and findings for the supplied files. A clean review is not a full repository audit.
- Accidental complexity: A finding whose removal preserves the required behavior. A blocking finding prevents completion.
- Clever measurements: Recorded code-structure measurements before and after changes. They remain separate from Tarpit findings.
- Project journal: A generated view of saved tasks and attempts in creation order. Stable task and run UUIDs identify the entries. Task journals also list recorded lifecycle events from `.molly/lifecycle.jsonl`.
- Recorded pull request: A human-opened GitHub pull request URL stored after molly:pr-opened --approve. Molly does not open the pull request.
- Recorded merge: A 40-character merge commit SHA stored after molly:merged --approve. Molly does not merge.
- Handoff: A bounded envelope for a child Bloom workspace. The recipient cannot widen file scope, edit the protected test, or merge.

The database records remain the source of truth. Editing this file or JOURNAL.md does not change a task or its attempts.

MARKDOWN;
    }

    private function updateGlossary(string $root, string $path): void
    {
        $existing = $this->readExisting($path);
        $start = '<!-- molly:glossary:start -->';
        $end = '<!-- molly:glossary:end -->';
        $section = $start."\n".$this->glossary().$end;
        $contents = $existing ?? "# Project glossary\n";
        if (str_contains($contents, $start) || str_contains($contents, $end)) {
            if (substr_count($contents, $start) !== 1 || substr_count($contents, $end) !== 1 || strpos($contents, $end) < strpos($contents, $start)) {
                throw new RuntimeException('JOURNAL_WRITE_FAILED: The Molly glossary section markers are incomplete or repeated. Repair the markers before exporting.');
            }
            $contents = substr_replace($contents, $section, strpos($contents, $start), strpos($contents, $end) + strlen($end) - strpos($contents, $start));
        } else {
            $contents .= "\n".$section."\n";
        }
        if ($contents !== $existing) {
            $this->write($root, $path, $contents, $existing === null ? false : hash('sha256', $existing));
        }
    }

    private function prepareDirectory(string $root): void
    {
        $this->ensureDirectory($root.'/.molly');
        $path = $root.'/.molly/.gitignore';
        $existing = $this->readExisting($path);
        $lines = explode("\n", rtrim($existing ?? '', "\r\n"));
        if (end($lines) !== '*') {
            $contents = ($existing ?? '').($existing !== null && ! str_ends_with($existing, "\n") ? "\n" : '')."*\n";
            $this->write($root, $path, $contents, $existing === null ? false : hash('sha256', $existing));
        }
    }

    private function readExisting(string $path): ?string
    {
        $this->validateFile($path);
        try {
            return is_file($path) ? File::get($path) : null;
        } catch (Throwable $exception) {
            throw new RuntimeException('JOURNAL_WRITE_FAILED: Molly could not read an existing journal support file.', previous: $exception);
        }
    }

    private function render(Task $task): string
    {
        $lines = [
            '# Task journal', '',
            'This file is a snapshot of saved evidence. The host database remains the source of truth. Export again to refresh it.', '',
            '- Task UUID: '.$this->escape($task->id),
            '- Nickname: '.$this->escape($task->nickname ?? 'Unnamed'),
            '- Status: '.$this->escape($task->status),
            '- Created: '.$this->escape($task->created_at?->toIso8601String()),
            '- Updated: '.$this->escape($task->updated_at?->toIso8601String()),
            '- Stop requested: '.$this->escape($task->stop_requested_at?->toIso8601String() ?? 'No'),
            '- Required test: '.$this->escape($task->test_path),
            '- Test protection: '.($task->allow_test_edits ? 'writable for this task' : 'protected'),
            '- Approved test digest: '.$this->escape($task->test_digest ?? 'none'),
            ...$this->testLockLines($task),
            ...$this->issueLines($task),
            ...$this->lifecycleSummary($task), '',
            '## Requested work', '', $this->quote($task->prompt), '',
            '## Editable files', '',
        ];
        foreach ($task->paths ?? [] as $path) {
            $lines[] = '- '.$this->escape($path);
        }
        $lines = [...$lines, '', '## Attempts', ''];
        if ($task->runs->isEmpty()) {
            $lines[] = 'No attempts recorded.';
        }
        foreach ($task->runs as $index => $run) {
            $lines = [...$lines, ...$this->attempt($run, $index + 1)];
        }

        return implode("\n", $lines)."\n";
    }

    /** @return list<string> */
    private function attempt(Run $run, int $number): array
    {
        $report = $run->report ?? [];
        $lines = [
            '### Attempt '.$number, '',
            '- Run UUID: '.$this->escape($run->id),
            '- Status: '.$this->escape($run->status),
            '- Created: '.$this->escape($run->created_at?->toIso8601String()),
            '- Updated: '.$this->escape($run->updated_at?->toIso8601String()), '',
        ];
        foreach (['summary' => 'Summary', 'error' => 'Failure', 'stop_reason' => 'Stop reason'] as $key => $label) {
            if (is_string($report[$key] ?? null) && $report[$key] !== '') {
                $lines = [...$lines, $label.':', '', $this->quote($report[$key]), ''];
            }
        }

        return [...$lines,
            ...$this->verification($report['verification'] ?? []),
            ...$this->receipts($report['verification_receipts'] ?? []),
            ...$this->review($report['review'] ?? []),
            ...$this->measurements($report),
        ];
    }

    /**
     * @param  array<string, mixed>  $verification
     * @return list<string>
     */
    private function verification(array $verification): array
    {
        $lines = ['#### Pest verification', '', '- Status: '.$this->escape($verification['status'] ?? null)];
        foreach (['tests', 'assertions', 'failures', 'errors', 'skipped'] as $key) {
            $lines[] = '- '.ucfirst($key).': '.$this->escape($verification[$key] ?? null);
        }
        foreach (['reason', 'error'] as $key) {
            if (is_string($verification[$key] ?? null)) {
                $lines = [...$lines, '', $this->quote($verification[$key])];
            }
        }

        return [...$lines, ''];
    }

    /**
     * @param  list<array<string, mixed>>  $receipts
     * @return list<string>
     */
    private function receipts(array $receipts): array
    {
        if ($receipts === []) {
            return [];
        }

        $lines = ['#### Verification receipts', ''];
        foreach ($receipts as $receipt) {
            $lines[] = '- '.$this->escape($receipt['verifier'] ?? null).': '.$this->escape($receipt['state'] ?? null).' / '.$this->escape($receipt['evidence_digest'] ?? null);
        }

        return [...$lines, ''];
    }

    /**
     * @param  array<string, mixed>  $review
     * @return list<string>
     */
    private function review(array $review): array
    {
        $lines = ['#### Tarpit review', '', '- Status: '.$this->escape($review['status'] ?? (isset($review['checks']) ? 'See checks below' : null)), ''];
        foreach (range('A', 'G') as $code) {
            $check = $review['checks'][$code] ?? [];
            $lines[] = '- '.$code.': '.$this->escape($check['status'] ?? null);
            if (is_string($check['evidence'] ?? null)) {
                $lines = [...$lines, '', $this->quote($check['evidence']), ''];
            }
        }
        foreach (['reason', 'error'] as $key) {
            if (is_string($review[$key] ?? null)) {
                $lines = [...$lines, '', $this->quote($review[$key]), ''];
            }
        }
        foreach ($review['findings'] ?? [] as $finding) {
            $lines = [...$lines, '',
                'Finding '.$this->escape($finding['code'] ?? null).': '.$this->escape($finding['severity'] ?? null).' / '.$this->escape($finding['classification'] ?? null),
                '- File: '.$this->escape($finding['path'] ?? null).':'.$this->escape($finding['line'] ?? null), '',
                $this->quote($finding['problem'] ?? null), '',
                'Suggested change:', '', $this->quote($finding['recommendation'] ?? null), '',
            ];
        }
        if (isset($review['findings']) && $review['findings'] === []) {
            $lines = [...$lines, '', 'No findings recorded.'];
        }

        return [...$lines, ''];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function measurements(array $report): array
    {
        $lines = ['#### Clever measurements', '', 'Measurements are separate from Tarpit findings. Lower counts alone do not prove a simpler design.', ''];
        foreach (['complexity_before' => 'Before changes', 'complexity_after' => 'After changes'] as $key => $label) {
            $measurement = $report[$key] ?? [];
            $lines[] = '- '.$label.': '.$this->escape($measurement['status'] ?? null);
            if (is_string($measurement['reason'] ?? null)) {
                $lines = [...$lines, '', $this->quote($measurement['reason']), ''];
            }
            foreach ($measurement['probes'] ?? [] as $probe) {
                $lines[] = '- '.$this->escape($probe['name'] ?? $probe['key'] ?? null).': '.$this->escape($probe['status'] ?? null);
                foreach ($probe['metrics'] ?? [] as $name => $value) {
                    if (is_numeric($value) || is_bool($value)) {
                        $lines[] = '  - '.$this->escape(str_replace('_', ' ', $name)).': '.$this->escape($value);
                    }
                }
                if (is_string($probe['skip_reason'] ?? null)) {
                    $lines = [...$lines, '', $this->quote($probe['skip_reason']), ''];
                }
            }
        }

        return [...$lines, ''];
    }

    private function escape(mixed $value): string
    {
        if (! is_scalar($value)) {
            return 'Not recorded';
        }
        $text = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
        $text = preg_replace('/[\x00-\x1F\x7F]/', ' ', $text);
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return preg_replace('/([\\\\`*_{}\[\]()#+.!|>~-])/', '\\\\$1', $text);
    }

    private function quote(mixed $value): string
    {
        if (! is_string($value)) {
            return '> Not recorded';
        }

        return implode("\n", array_map(fn (string $line): string => '> '.$this->escape($line), preg_split('/\R/', $value)));
    }

    private function write(string $root, string $path, string $markdown, string|false|null $expectedHash = null): void
    {
        $this->ensureDirectory($root.'/.molly');
        $this->ensureDirectory(dirname($path));
        $this->validateFile($path);
        $temporary = @tempnam(dirname($path), '.journal-');
        if ($temporary === false) {
            throw new RuntimeException('JOURNAL_WRITE_FAILED: Molly could not create the journal file.');
        }

        try {
            if (dirname($temporary) !== dirname($path) || File::put($temporary, $markdown) !== strlen($markdown)) {
                throw new RuntimeException('The journal could not be written in full.');
            }
            $this->validateDirectory($root.'/.molly');
            $this->validateDirectory(dirname($path));
            $this->validateFile($path);
            if ($expectedHash !== null && (is_file($path) ? @hash_file('sha256', $path) : false) !== $expectedHash) {
                throw new RuntimeException('The existing file changed during export.');
            }
            if (! File::move($temporary, $path)) {
                throw new RuntimeException('The journal could not be replaced.');
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('JOURNAL_WRITE_FAILED: Molly could not save the journal. The task and its attempts are unchanged.', previous: $exception);
        } finally {
            if (is_file($temporary) && ! is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function ensureDirectory(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || (file_exists($path) && ! is_dir($path))) {
            throw new RuntimeException('JOURNAL_PATH_INVALID: Journal directories must be real directories, not links or files.');
        }
        if (! is_dir($path) && ! @mkdir($path, 0700) && ! is_dir($path)) {
            throw new RuntimeException('JOURNAL_WRITE_FAILED: Molly could not create the journal directory.');
        }
        $this->validateDirectory($path);
    }

    private function validateDirectory(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || ! is_dir($path) || realpath($path) !== $path) {
            throw new RuntimeException('JOURNAL_PATH_INVALID: Journal directories must remain inside the workspace without links.');
        }
    }

    private function validateFile(string $path): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat !== false && (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1)) {
            throw new RuntimeException('JOURNAL_PATH_INVALID: The journal destination must be a regular file without links.');
        }
    }
}
