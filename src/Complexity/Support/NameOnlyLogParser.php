<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Support;

/**
 * Parses `git log --pretty=format:@%X --name-only` output: lines starting
 * with `@` mark a commit (the remainder is the marker. author name or
 * hash), every other non-empty line is a touched path. Same behavior as
 * the talk's awk one-liner, including its `^@`-path ambiguity. a path
 * literally starting with `@` would be misread; documented, not "fixed".
 */
final class NameOnlyLogParser
{
    /**
     * @return array{commits: int, perFile: array<string, array{touches: int, authors: array<string, true>}>}
     */
    public function aggregate(string $output): array
    {
        $commits = 0;
        $perFile = [];
        $marker = '';

        $line = strtok($output, "\n");

        while ($line !== false) {
            $line = rtrim($line, "\r");

            if ($line !== '') {
                if ($line[0] === '@') {
                    $commits++;
                    $marker = substr($line, 1);
                } else {
                    $entry = $perFile[$line] ?? ['touches' => 0, 'authors' => []];
                    $entry['touches']++;
                    $entry['authors'][$marker] = true;
                    $perFile[$line] = $entry;
                }
            }

            $line = strtok("\n");
        }

        return ['commits' => $commits, 'perFile' => $perFile];
    }
}
