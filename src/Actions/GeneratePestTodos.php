<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;

/**
 * Read the list under an "Acceptance criteria" heading in an issue body and
 * render one Pest todo per criterion. The file is a starting point for a
 * test-authoring run: Pest reports a todo as incomplete, so it never counts
 * as a passing test.
 */
class GeneratePestTodos
{
    public const MAX_CRITERIA = 30;

    public const MAX_CRITERION_LENGTH = 500;

    /** @return list<string> */
    public function criteria(?string $markdown): array
    {
        $criteria = [];
        $inSection = false;
        foreach (preg_split('/\R/', (string) $markdown) as $line) {
            if (preg_match('/\A {0,3}(?:#{1,6}\s+|\*\*|__)?acceptance criteria\b/i', $line) === 1) {
                $inSection = true;

                continue;
            }
            if (! $inSection) {
                continue;
            }
            if (preg_match('/\A {0,3}#{1,6}\s/', $line) === 1) {
                break;
            }
            if (preg_match('/\A {0,3}(?:[-*+]|\d{1,3}[.)])\s+(?:\[[ xX]\]\s+)?(.+)\z/', $line, $match) === 1) {
                $criteria[] = trim((string) preg_replace('/\s+/', ' ', $match[1]));
            }
        }

        $criteria = array_values(array_filter($criteria, fn (string $criterion): bool => $criterion !== ''));
        if ($criteria === []) {
            throw new RuntimeException('ISSUE_CRITERIA_MISSING: The issue body has no list under an "Acceptance criteria" heading. Add the list to the issue, or import it without --todos.');
        }
        if (count($criteria) > self::MAX_CRITERIA) {
            throw new RuntimeException('ISSUE_CRITERIA_INVALID: The issue lists more than '.self::MAX_CRITERIA.' acceptance criteria. Split the issue.');
        }
        foreach ($criteria as $criterion) {
            if (mb_strlen($criterion) > self::MAX_CRITERION_LENGTH || ! mb_check_encoding($criterion, 'UTF-8')) {
                throw new RuntimeException('ISSUE_CRITERIA_INVALID: Keep each acceptance criterion to '.self::MAX_CRITERION_LENGTH.' UTF-8 characters.');
            }
        }

        return $criteria;
    }

    /** @param list<string> $criteria */
    public function render(array $criteria, string $issueUrl): string
    {
        $lines = [
            '<?php',
            '',
            '// Acceptance criteria from '.$issueUrl,
            '// Molly wrote one todo per criterion. Replace each todo with a test that',
            '// asserts the behavior. Pest reports a todo as incomplete, never as passed.',
            '',
        ];
        foreach ($criteria as $index => $criterion) {
            $lines[] = 'it('.var_export($this->testName($index, $criterion), true).')->todo();';
        }

        return implode("\n", $lines)."\n";
    }

    public function testName(int $index, string $criterion): string
    {
        return 'criterion '.($index + 1).': '.$criterion;
    }
}
