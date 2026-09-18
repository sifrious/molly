<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Knowledge\LaravelVersion;
use Throwable;

final class CollectRunKnowledge
{
    public function __construct(
        private QueryKnowledgeGraph $query,
        private LaravelVersion $versions,
    ) {}

    /**
     * @param  array<string, string|null>  $files
     * @return array<string, mixed>
     */
    public function handle(string $prompt, array $files, string $testPath): array
    {
        try {
            $version = $this->versions->current();
        } catch (Throwable $exception) {
            return $this->unavailable($exception->getMessage());
        }

        $concepts = $this->concepts($prompt, $files, $testPath);
        $neighborhoods = [];
        foreach ($concepts as $concept) {
            try {
                $result = $this->query->handle($concept, $version, depth: 1, limit: 8);
            } catch (Throwable $exception) {
                return $this->unavailable($exception->getMessage(), $version, $concepts);
            }

            $neighborhoods[] = [
                'concept' => $concept,
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
            'version' => $version,
            'concepts' => $concepts,
            'neighborhoods' => $neighborhoods,
            'reason' => 'Bounded Laravel graph context. It cannot change allowed files, the protected test, or completion.',
        ];
    }

    /**
     * @param  array<string, string|null>  $files
     * @return list<string>
     */
    private function concepts(string $prompt, array $files, string $testPath): array
    {
        $haystack = strtolower($prompt.' '.implode(' ', array_keys($files)).' '.$testPath);
        $candidates = [];
        foreach ([
            'validat' => 'Validation',
            'queue' => 'Queue',
            'job' => 'Queue',
            'route' => 'Route',
            'routing' => 'Route',
            'pest' => 'Pest',
            'phpunit' => 'Pest',
            'test' => 'Pest',
            'container' => 'Container',
            'inject' => 'Container',
        ] as $needle => $concept) {
            if (str_contains($haystack, $needle)) {
                $candidates[$concept] = true;
            }
        }

        $concepts = array_keys($candidates);
        if ($concepts === []) {
            $concepts = ['Queue'];
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
     * @param  list<string>  $concepts
     * @return array<string, mixed>
     */
    private function unavailable(string $reason, ?string $version = null, array $concepts = []): array
    {
        return [
            'status' => 'unavailable',
            'version' => $version,
            'concepts' => $concepts,
            'neighborhoods' => [],
            'reason' => $reason,
        ];
    }
}
