<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Support;

/**
 * Classifies lines as blank, comment, or code with a per-line state
 * machine. a heuristic, deliberately NOT a parser (the package's evidence
 * rules ban parsers and ASTs). Matches cloc's semantics where they differ
 * from a parser's: mixed code+comment lines count as code, blank lines
 * inside block comments count as blank, and `#` always opens a PHP comment
 * (so PHP 8 attributes count as comments, exactly as cloc counts them).
 * Comment markers inside strings and heredocs are misread. a documented,
 * shared blind spot with the hand-verify one-liners.
 */
final class LineClassifier
{
    /**
     * @var array<string, array{line: list<string>, blocks: list<array{string, string}>}>
     */
    private const array PROFILES = [
        'php' => ['line' => ['//', '#'], 'blocks' => [['/*', '*/']]],
        'blade.php' => ['line' => ['//', '#'], 'blocks' => [['/*', '*/'], ['{{--', '--}}']]],
        'js' => ['line' => ['//'], 'blocks' => [['/*', '*/']]],
        'ts' => ['line' => ['//'], 'blocks' => [['/*', '*/']]],
        'jsx' => ['line' => ['//'], 'blocks' => [['/*', '*/']]],
        'tsx' => ['line' => ['//'], 'blocks' => [['/*', '*/']]],
        'vue' => ['line' => ['//'], 'blocks' => [['/*', '*/'], ['<!--', '-->']]],
        'css' => ['line' => [], 'blocks' => [['/*', '*/']]],
        'json' => ['line' => [], 'blocks' => []],
    ];

    /**
     * @return array{code: int, comment: int, blank: int}
     */
    public function count(string $contents, string $profile): array
    {
        $rules = self::PROFILES[$profile] ?? ['line' => [], 'blocks' => []];

        $lines = preg_split('/\r\n|\r|\n/', $contents);

        if ($lines === false) {
            return ['code' => 0, 'comment' => 0, 'blank' => 0];
        }

        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $counts = ['code' => 0, 'comment' => 0, 'blank' => 0];
        $openBlockCloser = null;

        foreach ($lines as $line) {
            $counts[$this->classifyLine($line, $openBlockCloser, $rules)]++;
        }

        return $counts;
    }

    /**
     * @param  array{line: list<string>, blocks: list<array{string, string}>}  $rules
     * @return 'blank'|'code'|'comment'
     */
    private function classifyLine(string $line, ?string &$openBlockCloser, array $rules): string
    {
        $rest = trim($line);

        if ($rest === '') {
            return 'blank';
        }

        $hasCode = false;

        while (true) {
            if ($openBlockCloser !== null) {
                $position = strpos($rest, $openBlockCloser);

                if ($position === false) {
                    break;
                }

                $rest = ltrim(substr($rest, $position + strlen($openBlockCloser)));
                $openBlockCloser = null;

                if ($rest === '') {
                    break;
                }

                continue;
            }

            $opener = $this->earliestOpener($rest, $rules);

            if ($opener === null) {
                $hasCode = true;

                break;
            }

            [$offset, $token, $closer] = $opener;

            if ($offset > 0) {
                $hasCode = true;
            }

            if ($closer === null) {
                break;
            }

            $openBlockCloser = $closer;
            $rest = substr($rest, $offset + strlen($token));
        }

        return $hasCode ? 'code' : 'comment';
    }

    /**
     * Earliest comment opener in the string; at equal offsets the longer
     * token wins. Returns [offset, token, block closer or null].
     *
     * @param  array{line: list<string>, blocks: list<array{string, string}>}  $rules
     * @return array{int, string, string|null}|null
     */
    private function earliestOpener(string $rest, array $rules): ?array
    {
        $best = null;

        foreach ($rules['line'] as $token) {
            $best = $this->better($best, $rest, $token, null);
        }

        foreach ($rules['blocks'] as [$open, $close]) {
            $best = $this->better($best, $rest, $open, $close);
        }

        return $best;
    }

    /**
     * @param  array{int, string, string|null}|null  $best
     * @return array{int, string, string|null}|null
     */
    private function better(?array $best, string $rest, string $token, ?string $closer): ?array
    {
        $position = strpos($rest, $token);

        if ($position === false) {
            return $best;
        }

        if ($best === null || $position < $best[0] || ($position === $best[0] && strlen($token) > strlen($best[1]))) {
            return [$position, $token, $closer];
        }

        return $best;
    }
}
