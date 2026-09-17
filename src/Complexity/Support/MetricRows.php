<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Support;

use Illuminate\Support\Str;

/**
 * Turns a probe's metrics object into flat label/value display rows.
 */
final class MetricRows
{
    public const int MAX_LIST_ROWS = 10;

    /**
     * @param  array<string, mixed>  $metrics
     * @return list<array{label: string, value: string, indent: bool}>
     */
    public function build(array $metrics): array
    {
        $rows = [];

        foreach ($metrics as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $rows[] = [
                    'label' => Str::headline($key),
                    'value' => $this->formatScalar($value),
                    'indent' => false,
                ];

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $count = 0;

            foreach ($value as $rowKey => $row) {
                if ($count >= self::MAX_LIST_ROWS) {
                    $rows[] = [
                        'label' => sprintf('… %d more in the report', count($value) - $count),
                        'value' => '',
                        'indent' => true,
                    ];

                    break;
                }

                if (! is_array($row)) {
                    continue;
                }

                $label = $row['path'] ?? $row['file'] ?? $rowKey;
                $detail = [];

                foreach ($row as $field => $fieldValue) {
                    if (in_array($field, ['path', 'file'], true) || ! (is_scalar($fieldValue) || $fieldValue === null)) {
                        continue;
                    }

                    $detail[] = sprintf('%s %s', Str::headline((string) $field), $this->formatScalar($fieldValue));
                }

                $rows[] = [
                    'label' => is_string($label) ? $label : (string) $rowKey,
                    'value' => implode(' · ', $detail),
                    'indent' => true,
                ];

                $count++;
            }
        }

        return $rows;
    }

    private function formatScalar(bool|float|int|string|null $value): string
    {
        return match (true) {
            $value === null => 'Unavailable',
            is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };
    }
}
