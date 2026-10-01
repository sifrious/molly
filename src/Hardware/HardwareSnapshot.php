<?php

namespace Sifrious\Molly\Hardware;

/**
 * The molly.hardware-snapshot/1 contract. snapshot_sha256 covers the canonical
 * encoding of facts only, so two readers can prove they used the same facts.
 */
class HardwareSnapshot
{
    public const SCHEMA = 'molly.hardware-snapshot/1';

    public const CANONICALIZATION = 'sha256 of the facts object encoded as JSON with object keys sorted recursively, list order kept, unescaped slashes and unicode, and no whitespace';

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $facts
     * @param  array<string, mixed>  $host
     * @return array<string, mixed>
     */
    public static function make(array $facts, array $host, string $measuredAt): array
    {
        return [
            'schema' => self::SCHEMA,
            'measured_at' => $measuredAt,
            'host' => $host,
            'facts' => $facts,
            'unknowns' => self::unknowns($facts),
            'canonicalization' => self::CANONICALIZATION,
            'snapshot_sha256' => self::hash($facts),
        ];
    }

    /** @param  array<string, mixed>  $facts */
    public static function hash(array $facts): string
    {
        return hash('sha256', self::canonical($facts));
    }

    /** @param  array<string, mixed>  $facts */
    public static function canonical(array $facts): string
    {
        return json_encode(self::sorted($facts), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $facts
     * @return list<array{fact: string, reason: string}>
     */
    public static function unknowns(array $facts): array
    {
        $unknowns = [];
        foreach ($facts as $group => $entries) {
            foreach ($entries as $name => $fact) {
                if (($fact['status'] ?? null) === 'unknown') {
                    $unknowns[] = ['fact' => "{$group}.{$name}", 'reason' => (string) $fact['reason']];
                }
            }
        }

        return $unknowns;
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sorted(...), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
