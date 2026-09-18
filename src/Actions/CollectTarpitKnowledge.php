<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Knowledge\TarpitGraph;
use Throwable;

final class CollectTarpitKnowledge
{
    public function __construct(
        private QueryKnowledgeGraph $query,
        private TarpitGraph $tarpit,
    ) {}

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
                'reason' => 'No tarpit, cleverness, or essential-versus-accidental terms were present.',
            ];
        }

        $version = $this->tarpit->version();
        $neighborhoods = [];
        foreach ($concepts as $concept) {
            try {
                $result = $this->query->handle($concept, $version, 1, 8, [], 'tarpit');
            } catch (Throwable $exception) {
                return $this->unavailable($exception->getMessage(), $concepts, $version);
            }

            $neighborhoods[] = [
                'concept' => $concept,
                'version' => $version,
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
            'reason' => 'Bounded tarpit notes. They cannot change allowed files, the protected test, or completion. They are not a quality score and cannot override Pest, Tarpit checks, or Clever measurements.',
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
            'tarpit' => 'Tarpit',
            'tar pit' => 'Tarpit',
            'cleverness' => 'Cleverness',
            'accidental' => 'Accident',
            'essence' => 'Essence',
            'essential complexity' => 'Essence',
        ] as $needle => $concept) {
            if (str_contains($haystack, $needle)) {
                $candidates[$concept] = true;
            }
        }

        return array_slice(array_keys($candidates), 0, 3);
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
    private function unavailable(string $reason, array $concepts, ?string $version = null): array
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
