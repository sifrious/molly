<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Support;

/**
 * Exact PHP ports of the talk's C2 grep patterns, applied to raw file
 * contents including comments. grep counts them, so we do too (fidelity
 * over cleverness; divergence would break hand-verifiability).
 *
 * The facade split is a regex over use-statements plus Laravel's global
 * alias list. deliberately not a parser. The grep totals are never
 * altered by it; the split is reported alongside.
 */
final class WeldScanner
{
    /**
     * Port of: grep -o 'new [A-Z][A-Za-z0-9_\\]*\('
     * Deliberately no \b prefix and a single literal space, exactly like
     * the published pattern: `new \App\Foo(`, `new static(`, and
     * `new $var(` do not match.
     */
    private const string WELDED_NEW = '/new [A-Z][A-Za-z0-9_\\\\]*\(/';

    /**
     * Port of: grep -o '\b[A-Z][A-Za-z0-9_]*::[a-zA-Z_]+\(' minus
     * (self|static|parent)::. the grep exclusion is case-sensitive, so
     * given the leading [A-Z] here it is a genuine no-op, kept for parity
     * with the published pipeline.
     */
    private const string BARE_STATIC = '/\b([A-Z][A-Za-z0-9_]*)::[a-zA-Z_]+\(/';

    /**
     * Laravel's global facade aliases, valid without an import.
     */
    public const array DEFAULT_FACADE_ALIASES = [
        'App', 'Artisan', 'Auth', 'Blade', 'Broadcast', 'Bus', 'Cache', 'Concurrency',
        'Config', 'Context', 'Cookie', 'Crypt', 'Date', 'DB', 'Event', 'Exceptions',
        'File', 'Gate', 'Hash', 'Http', 'Lang', 'Log', 'Mail', 'Notification',
        'Password', 'Pipeline', 'Process', 'Queue', 'RateLimiter', 'Redirect',
        'Redis', 'Request', 'Response', 'Route', 'Schedule', 'Schema', 'Session',
        'Storage', 'URL', 'Validator', 'View', 'Vite',
    ];

    /**
     * @param  list<string>  $extraFacades
     * @return array{welded_new: list<array{line: int, match: string}>, bare_static: list<array{line: int, match: string, class: string, kind: 'candidate'|'facade'}>}
     */
    public function scan(string $contents, array $extraFacades = []): array
    {
        $aliases = $this->facadeAliases($contents, $extraFacades);

        $weldedNew = [];
        $bareStatic = [];

        $lines = preg_split('/\r\n|\r|\n/', $contents);

        foreach ($lines === false ? [] : $lines as $index => $line) {
            $lineNumber = $index + 1;

            if (preg_match_all(self::WELDED_NEW, $line, $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $weldedNew[] = ['line' => $lineNumber, 'match' => $match];
                }
            }

            if (preg_match_all(self::BARE_STATIC, $line, $matches) > 0) {
                foreach ($matches[0] as $position => $match) {
                    $class = $matches[1][$position];

                    // Case-sensitive, matching grep -vE '(self|static|parent)::'. a
                    // no-op given the leading [A-Z], but kept so the count equals the
                    // printed hand-verify pipeline exactly.
                    if (in_array($class, ['self', 'static', 'parent'], true)) {
                        continue;
                    }

                    $bareStatic[] = [
                        'line' => $lineNumber,
                        'match' => $match,
                        'class' => $class,
                        'kind' => isset($aliases[$class]) ? 'facade' : 'candidate',
                    ];
                }
            }
        }

        return ['welded_new' => $weldedNew, 'bare_static' => $bareStatic];
    }

    /**
     * Class basenames that resolve from the container in this file:
     * imported facades (plain, aliased, and group-use. any namespace
     * containing \Facades\), Laravel's global aliases, and configured
     * extras. Regex over use-statements only. not a parser.
     *
     * @param  list<string>  $extraFacades
     * @return array<string, true>
     */
    private function facadeAliases(string $contents, array $extraFacades): array
    {
        $aliases = [];

        foreach ([...self::DEFAULT_FACADE_ALIASES, ...$extraFacades] as $alias) {
            $aliases[$alias] = true;
        }

        if (preg_match_all(
            '/^use\s+([A-Za-z0-9_\\\\]*\\\\Facades\\\\[A-Za-z0-9_]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/mi',
            $contents,
            $matches,
            PREG_SET_ORDER,
        ) > 0) {
            foreach ($matches as $match) {
                $alias = ($match[2] ?? '') !== ''
                    ? $match[2]
                    : substr((string) strrchr($match[1], '\\'), 1);

                $aliases[$alias] = true;
            }
        }

        if (preg_match_all(
            '/^use\s+[A-Za-z0-9_\\\\]*\\\\Facades\\\\\{([^}]*)\}/mi',
            $contents,
            $matches,
        ) > 0) {
            foreach ($matches[1] as $group) {
                foreach (explode(',', $group) as $item) {
                    $parts = preg_split('/\s+as\s+/i', trim($item));

                    if ($parts === false || $parts === ['']) {
                        continue;
                    }

                    $name = trim($parts[count($parts) - 1]);
                    $name = substr((string) strrchr('\\'.$name, '\\'), 1);

                    if ($name !== '') {
                        $aliases[$name] = true;
                    }
                }
            }
        }

        return $aliases;
    }
}
