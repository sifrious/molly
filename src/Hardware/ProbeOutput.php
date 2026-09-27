<?php

namespace Sifrious\Molly\Hardware;

use DOMDocument;
use DOMElement;

/**
 * Pure parsers for the raw text that macOS and Ollama return to HardwareProbe.
 * Each parser returns null when the text does not contain the expected value,
 * so the probe can record an unknown instead of a guessed number.
 */
class ProbeOutput
{
    public static function integer(string $output): ?int
    {
        $value = trim($output);

        return preg_match('/^\d+$/', $value) === 1 ? (int) $value : null;
    }

    public static function flag(string $output): ?bool
    {
        return match (trim($output)) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }

    /** @return array<string, string> */
    public static function swVers(string $output): array
    {
        $values = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^\s*(ProductName|ProductVersion|BuildVersion)\s*:\s*(.+?)\s*$/', $line, $match) === 1) {
                $values[$match[1]] = $match[2];
            }
        }

        return $values;
    }

    /**
     * Page counts from vm_stat, keyed by a stable name.
     *
     * @return array<string, int>
     */
    public static function vmStat(string $output): array
    {
        $names = [
            'Pages free' => 'free',
            'Pages active' => 'active',
            'Pages inactive' => 'inactive',
            'Pages speculative' => 'speculative',
            'Pages wired down' => 'wired',
            'Pages purgeable' => 'purgeable',
            'Pages occupied by compressor' => 'compressor_occupied',
            'Pages stored in compressor' => 'compressor_stored',
        ];

        $pages = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^"?([^:"]+)"?:\s+(\d+)\.?\s*$/', $line, $match) === 1 && isset($names[trim($match[1])])) {
                $pages[$names[trim($match[1])]] = (int) $match[2];
            }
        }

        return $pages;
    }

    public static function pressureLevel(int $level): ?string
    {
        return match ($level) {
            1 => 'normal',
            2 => 'warning',
            4 => 'critical',
            default => null,
        };
    }

    /** @return array<string, mixed>|null The first display entry that reports a GPU. */
    public static function displays(string $output): ?array
    {
        $decoded = json_decode($output, true);
        $entries = is_array($decoded) && is_array($decoded['SPDisplaysDataType'] ?? null) ? $decoded['SPDisplaysDataType'] : [];

        foreach ($entries as $entry) {
            if (is_array($entry) && (isset($entry['sppci_model']) || isset($entry['spdisplays_mtlgpufamilysupport']) || isset($entry['spdisplays_metalfamilysupport']))) {
                return $entry;
            }
        }

        return null;
    }

    public static function ollamaVersion(string $output): ?string
    {
        return preg_match_all('/(\d+\.\d+\.\d+[\w.+-]*)/', $output, $matches) > 0 ? end($matches[1]) : null;
    }

    /**
     * The data row of `df -kP`. The mount point may contain spaces.
     *
     * @return array{device: string, total_kib: int, used_kib: int, available_kib: int, mount_point: string}|null
     */
    public static function dfPortable(string $output): ?array
    {
        $lines = array_values(array_filter(preg_split('/\R/', trim($output)) ?: [], fn (string $line): bool => trim($line) !== ''));
        $row = $lines[1] ?? null;

        if ($row === null || preg_match('/^(\S+)\s+(\d+)\s+(\d+)\s+(\d+)\s+\d+%\s+(.+)$/', $row, $match) !== 1) {
            return null;
        }

        return [
            'device' => $match[1],
            'total_kib' => (int) $match[2],
            'used_kib' => (int) $match[3],
            'available_kib' => (int) $match[4],
            'mount_point' => rtrim($match[5]),
        ];
    }

    /** @return array<string, mixed>|null The top-level dictionary of an XML property list. */
    public static function plist(string $output): ?array
    {
        if (trim($output) === '') {
            return null;
        }

        $document = new DOMDocument;
        if (! @$document->loadXML($output, LIBXML_NONET)) {
            return null;
        }

        foreach ($document->documentElement?->childNodes ?? [] as $node) {
            if ($node instanceof DOMElement) {
                $value = self::plistValue($node);

                return is_array($value) ? $value : null;
            }
        }

        return null;
    }

    private static function plistValue(DOMElement $node): mixed
    {
        $children = array_values(array_filter(iterator_to_array($node->childNodes), fn ($child): bool => $child instanceof DOMElement));

        return match ($node->nodeName) {
            'dict' => self::plistDict($children),
            'array' => array_map(fn (DOMElement $child): mixed => self::plistValue($child), $children),
            'integer' => (int) $node->textContent,
            'real' => (float) $node->textContent,
            'true' => true,
            'false' => false,
            default => $node->textContent,
        };
    }

    /**
     * @param  list<DOMElement>  $children
     * @return array<string, mixed>
     */
    private static function plistDict(array $children): array
    {
        $dict = [];
        for ($i = 0; $i + 1 < count($children); $i += 2) {
            if ($children[$i]->nodeName === 'key') {
                $dict[$children[$i]->textContent] = self::plistValue($children[$i + 1]);
            }
        }

        return $dict;
    }
}
