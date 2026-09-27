<?php

namespace Sifrious\Molly\Actions;

/**
 * Identify a failed run by what failed, not by how the failure was worded.
 * The digest covers failing verifiers, the error code, the Pest reason and
 * failing test identities, and blocking Tarpit check codes with their paths.
 * Message text, line numbers, and timings are left out, so a reworded
 * failure keeps its fingerprint.
 */
class FingerprintRunFailure
{
    /**
     * @param  array<string, mixed>  $report
     * @return array{digest: string, inputs: array<string, mixed>}
     */
    public function handle(array $report): array
    {
        $failing = array_filter($report['verification_outcomes'] ?? [], fn (mixed $outcome): bool => is_array($outcome) && ($outcome['state'] ?? null) !== 'PASS');
        $inputs = [
            'verifiers' => $this->sorted(array_map(
                fn (string $name, array $outcome): string => $name.':'.($outcome['state'] ?? 'UNKNOWN'),
                array_keys($failing),
                $failing,
            )),
            'error' => is_string($report['error'] ?? null) && preg_match('/\A([A-Z][A-Z0-9_]+):/', $report['error'], $match) === 1 ? $match[1] : null,
            'pest' => is_array($report['verification'] ?? null) ? [
                'reason' => is_string($report['verification']['reason'] ?? null) ? $report['verification']['reason'] : null,
                'tests' => $this->sorted(array_map(
                    fn (array $test): string => basename((string) ($test['file'] ?? '')).'::'.$this->normalize((string) ($test['name'] ?? '')).'::'.($test['kind'] ?? ''),
                    array_filter($report['verification']['failing_tests'] ?? [], is_array(...)),
                )),
            ] : null,
            'tarpit' => $this->sorted(array_map(
                fn (array $finding): string => ($finding['code'] ?? '').':'.($finding['path'] ?? ''),
                array_filter($report['review']['findings'] ?? [], fn (mixed $finding): bool => is_array($finding) && ($finding['severity'] ?? null) === 'blocking'),
            )),
            'false_green' => is_array($report['false_green'] ?? null) ? ($report['false_green']['status'] ?? null) : null,
        ];

        return ['digest' => hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 'inputs' => $inputs];
    }

    private function normalize(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
    }

    /**
     * @param  array<int|string, string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
