<?php

namespace Sifrious\Molly\Actions;

use Throwable;

final class CollectNativePhpKnowledge
{
    public function __construct(private QueryKnowledgeGraph $query) {}

    /**
     * @param  array<string, string|null>  $files
     * @return array<string, mixed>
     */
    public function handle(string $prompt, array $files, string $testPath): array
    {
        $concepts = $this->concepts($prompt, $files, $testPath);
        if ($concepts === []) {
            return [
                'status' => 'omitted',
                'concepts' => [],
                'neighborhoods' => [],
                'reason' => 'No NativePHP desktop or mobile terms were present.',
            ];
        }

        $neighborhoods = [];
        foreach ($concepts as $concept) {
            try {
                $result = $this->query->handle($concept['concept'], $concept['version'], 1, 8, [], 'nativephp');
            } catch (Throwable $exception) {
                return $this->unavailable($exception->getMessage(), $concepts);
            }

            $neighborhoods[] = [
                'concept' => $concept['concept'],
                'version' => $concept['version'],
                'matched' => $result['nodes'] !== [],
                'truncated' => $result['truncated'],
                'nodes' => array_map(fn (array $node): array => [
                    'label' => $node['label'],
                    'type' => $node['type'],
                    'key' => $node['key'] ?? null,
                ], $result['nodes']),
                'edges' => array_map(fn (array $edge): array => [
                    'relation' => $edge['relation'],
                    'from' => $edge['from'] ?? null,
                    'to' => $edge['to'] ?? null,
                ], $result['edges']),
                'sources' => $this->sources($result),
            ];
        }

        return [
            'status' => 'advisory',
            'concepts' => array_column($concepts, 'concept'),
            'neighborhoods' => $neighborhoods,
            'reason' => 'Bounded NativePHP graph context. It cannot change allowed files, the protected test, or completion. Desktop v2 and Mobile v4 stay separate. Installed NativePHP packages are not required or claimed.',
        ];
    }

    /**
     * @param  array<string, string|null>  $files
     * @return list<array{concept: string, version: string}>
     */
    private function concepts(string $prompt, array $files, string $testPath): array
    {
        $haystack = strtolower($prompt.' '.implode(' ', array_keys($files)).' '.$testPath);
        $desktop = str_contains($haystack, 'desktop') || str_contains($haystack, 'macos');
        $mobile = str_contains($haystack, 'mobile') || str_contains($haystack, 'ios') || str_contains($haystack, 'android');
        $native = str_contains($haystack, 'nativephp') || str_contains($haystack, 'native php');

        if (! $desktop && ! $mobile && ! $native) {
            return [];
        }

        if ($native && ! $desktop && ! $mobile) {
            $desktop = true;
            $mobile = true;
        }

        $concepts = [];
        if ($desktop) {
            $concepts[] = ['concept' => 'Desktop', 'version' => 'desktop-2'];
        }
        if ($mobile) {
            $concepts[] = ['concept' => 'Mobile', 'version' => 'mobile-4'];
        }

        return array_slice($concepts, 0, 3);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array{title: string|null, revision: string|null}>
     */
    private function sources(array $result): array
    {
        $seen = [];
        foreach ([...$result['nodes'], ...$result['edges']] as $record) {
            foreach ($record['sources'] ?? [] as $source) {
                $key = ($source['title'] ?? '')."\0".($source['revision'] ?? '');
                $seen[$key] = [
                    'title' => $source['title'] ?? null,
                    'revision' => $source['revision'] ?? null,
                ];
            }
        }

        return array_slice(array_values($seen), 0, 8);
    }

    /**
     * @param  list<array{concept: string, version: string}>  $concepts
     * @return array<string, mixed>
     */
    private function unavailable(string $reason, array $concepts): array
    {
        return [
            'status' => 'unavailable',
            'concepts' => array_column($concepts, 'concept'),
            'neighborhoods' => [],
            'reason' => $reason,
        ];
    }
}
