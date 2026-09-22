<?php

namespace Sifrious\Molly\Journal;

/** Pure Markdown rendering for task journals (no filesystem, Eloquent, or services). */
final class JournalRenderer
{
    /**
     * @param  array<string, mixed>  $task
     */
    public function renderTask(array $task): string
    {
        $lines = [
            '# Task journal', '',
            'This file is a snapshot of saved evidence. The host database remains the source of truth. Export again to refresh it.', '',
            '- Task UUID: '.$this->escape($task['id'] ?? null),
            '- Nickname: '.$this->escape($task['nickname'] ?? 'Unnamed'),
            '- Status: '.$this->escape($task['status'] ?? null),
            '- Created: '.$this->escape($task['created_at'] ?? null),
            '- Updated: '.$this->escape($task['updated_at'] ?? null),
            '- Stop requested: '.$this->escape($task['stop_requested_at'] ?? 'No'),
            '- Required test: '.$this->escape($task['test_path'] ?? null),
            '- Test protection: '.(($task['allow_test_edits'] ?? false) ? 'writable for this task' : 'protected'),
            '- Approved test digest: '.$this->escape($task['test_digest'] ?? 'none'),
            ...$this->stringList($task['test_lock_lines'] ?? []),
            ...$this->stringList($task['issue_lines'] ?? []),
            ...$this->stringList($task['lifecycle_lines'] ?? []), '',
            '## Requested work', '', $this->quote($task['prompt'] ?? null), '',
            '## Editable files', '',
        ];

        foreach ($task['paths'] ?? [] as $path) {
            $lines[] = '- '.$this->escape($path);
        }

        $lines = [...$lines, '', '## Attempts', ''];
        $runs = $task['runs'] ?? [];
        if ($runs === []) {
            $lines[] = 'No attempts recorded.';
        }
        foreach (array_values($runs) as $index => $run) {
            if (! is_array($run)) {
                continue;
            }
            $lines = [...$lines, ...$this->attempt($run, $index + 1)];
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $run
     * @return list<string>
     */
    public function attempt(array $run, int $number): array
    {
        $report = is_array($run['report'] ?? null) ? $run['report'] : [];
        $lines = [
            '### Attempt '.$number, '',
            '- Run UUID: '.$this->escape($run['id'] ?? null),
            '- Status: '.$this->escape($run['status'] ?? null),
            '- Created: '.$this->escape($run['created_at'] ?? null),
            '- Updated: '.$this->escape($run['updated_at'] ?? null), '',
        ];
        foreach (['summary' => 'Summary', 'error' => 'Failure', 'stop_reason' => 'Stop reason'] as $key => $label) {
            if (is_string($report[$key] ?? null) && $report[$key] !== '') {
                $lines = [...$lines, $label.':', '', $this->quote($report[$key]), ''];
            }
        }

        return [...$lines,
            ...$this->verification(is_array($report['verification'] ?? null) ? $report['verification'] : []),
            ...$this->receipts(is_array($report['verification_receipts'] ?? null) ? $report['verification_receipts'] : []),
            ...$this->review(is_array($report['review'] ?? null) ? $report['review'] : []),
            ...$this->measurements($report),
        ];
    }

    /**
     * @param  array<string, mixed>  $verification
     * @return list<string>
     */
    public function verification(array $verification): array
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
    public function receipts(array $receipts): array
    {
        if ($receipts === []) {
            return [];
        }

        $lines = ['#### Verification receipts', ''];
        foreach ($receipts as $receipt) {
            if (! is_array($receipt)) {
                continue;
            }
            $lines[] = '- '.$this->escape($receipt['verifier'] ?? null).': '.$this->escape($receipt['state'] ?? null).' / '.$this->escape($receipt['evidence_digest'] ?? null);
        }

        return [...$lines, ''];
    }

    /**
     * @param  array<string, mixed>  $review
     * @return list<string>
     */
    public function review(array $review): array
    {
        $lines = ['#### Tarpit review', '', '- Status: '.$this->escape($review['status'] ?? (isset($review['checks']) ? 'See checks below' : null)), ''];
        foreach (range('A', 'G') as $code) {
            $check = is_array($review['checks'][$code] ?? null) ? $review['checks'][$code] : [];
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
            if (! is_array($finding)) {
                continue;
            }
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
    public function measurements(array $report): array
    {
        $lines = ['#### Clever measurements', '', 'Measurements are separate from Tarpit findings. Lower counts alone do not prove a simpler design.', ''];
        foreach (['complexity_before' => 'Before changes', 'complexity_after' => 'After changes'] as $key => $label) {
            $measurement = is_array($report[$key] ?? null) ? $report[$key] : [];
            $lines[] = '- '.$label.': '.$this->escape($measurement['status'] ?? null);
            if (is_string($measurement['reason'] ?? null)) {
                $lines = [...$lines, '', $this->quote($measurement['reason']), ''];
            }
            foreach ($measurement['probes'] ?? [] as $probe) {
                if (! is_array($probe)) {
                    continue;
                }
                $lines[] = '- '.$this->escape($probe['name'] ?? $probe['key'] ?? null).': '.$this->escape($probe['status'] ?? null);
                foreach ($probe['metrics'] ?? [] as $name => $value) {
                    if (is_numeric($value) || is_bool($value)) {
                        $lines[] = '  - '.$this->escape(str_replace('_', ' ', (string) $name)).': '.$this->escape($value);
                    }
                }
                if (is_string($probe['skip_reason'] ?? null)) {
                    $lines = [...$lines, '', $this->quote($probe['skip_reason']), ''];
                }
            }
        }

        return [...$lines, ''];
    }

    public const GLOSSARY_START = '<!-- molly:glossary:start -->';

    public const GLOSSARY_END = '<!-- molly:glossary:end -->';

    /**
     * @param  list<array<string, mixed>>  $entries  Chronological project entries (pre-ordered arrays)
     */
    public function renderProject(array $entries): string
    {
        $lines = [
            '# Project journal', '',
            'This file is a generated view of saved tasks and attempts. Stable task and run UUIDs identify the entries. The host database remains the source of truth.', '',
        ];
        if ($entries === []) {
            $lines[] = 'No tasks recorded.';
        }
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $lines = [...$lines, ...$this->projectEntry($entry), ''];
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    public function projectEntry(array $entry): array
    {
        $kind = $entry['kind'] ?? 'task';
        if ($kind === 'attempt') {
            $run = is_array($entry['run'] ?? null) ? $entry['run'] : $entry;
            $number = (int) ($entry['number'] ?? 1);
            $lines = $this->attempt($run, $number);
            if ($lines !== []) {
                $lines[0] = '## Attempt '.$number;
            }
            $identity = [
                '- Task UUID: '.$this->escape($entry['task_id'] ?? $entry['id'] ?? null),
                '- Nickname: '.$this->escape($entry['nickname'] ?? 'Unnamed'),
            ];

            return [...array_slice($lines, 0, 2), ...$identity, ...array_slice($lines, 2)];
        }

        return [
            '## Task created', '',
            '- Task UUID: '.$this->escape($entry['id'] ?? null),
            '- Nickname: '.$this->escape($entry['nickname'] ?? 'Unnamed'),
            '- Status: '.$this->escape($entry['status'] ?? null),
            '- Created: '.$this->escape($entry['created_at'] ?? null),
            '- Updated: '.$this->escape($entry['updated_at'] ?? null),
            '- Required test: '.$this->escape($entry['test_path'] ?? null),
            '- Test protection: '.(($entry['allow_test_edits'] ?? false) ? 'writable for this task' : 'protected'),
            '- Approved test digest: '.$this->escape($entry['test_digest'] ?? 'none'),
            ...$this->stringList($entry['test_lock_lines'] ?? []),
            ...$this->stringList($entry['issue_lines'] ?? []),
            ...$this->stringList($entry['lifecycle_lines'] ?? []), '',
            $this->quote($entry['prompt'] ?? null), '',
        ];
    }

    public function glossaryCopy(): string
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

    /**
     * Replace the managed glossary section; preserve user-authored content outside markers.
     * Pure string transform — no filesystem.
     */
    public function replaceManagedGlossary(string $existing): string
    {
        $start = self::GLOSSARY_START;
        $end = self::GLOSSARY_END;
        $section = $start."\n".$this->glossaryCopy().$end;
        $contents = $existing !== '' ? $existing : "# Project glossary\n";

        if (str_contains($contents, $start) || str_contains($contents, $end)) {
            if (substr_count($contents, $start) !== 1 || substr_count($contents, $end) !== 1 || strpos($contents, $end) < strpos($contents, $start)) {
                throw new \RuntimeException('JOURNAL_WRITE_FAILED: The Molly glossary section markers are incomplete or repeated. Repair the markers before exporting.');
            }

            return substr_replace($contents, $section, strpos($contents, $start), strpos($contents, $end) + strlen($end) - strpos($contents, $start));
        }

        return $contents."\n".$section."\n";
    }

    public function escape(mixed $value): string
    {
        if (! is_scalar($value)) {
            return 'Not recorded';
        }
        $text = is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value;
        $text = preg_replace('/[\x00-\x1F\x7F]/', ' ', $text) ?? $text;
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return preg_replace('/([\\\\`*_{}\[\]()#+.!|>~-])/', '\\\\$1', $text) ?? $text;
    }

    public function quote(mixed $value): string
    {
        if (! is_string($value)) {
            return '> Not recorded';
        }

        return implode("\n", array_map(fn (string $line): string => '> '.$this->escape($line), preg_split('/\R/', $value) ?: []));
    }

    /**
     * @param  list<mixed>  $lines
     * @return list<string>
     */
    private function stringList(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (is_string($line)) {
                $out[] = $line;
            }
        }

        return $out;
    }
}
