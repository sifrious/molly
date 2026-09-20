<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Knowledge\ContextPack;
use Sifrious\Molly\Knowledge\LaravelVersion;
use Throwable;

final class CollectRunKnowledge
{
    /** @var array<string, string> */
    private const NEEDLES = [
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
        'eloquent' => 'Eloquent',
        'model' => 'Eloquent',
        'event' => 'Events',
        'listener' => 'Events',
    ];

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
        return $this->pack($prompt, $files, $testPath)->toArray();
    }

    /**
     * @param  array<string, string|null>  $files
     */
    public function pack(string $prompt, array $files, string $testPath): ContextPack
    {
        $filePaths = array_values(array_map('strval', array_keys($files)));
        $queryBase = [
            'prompt_digest' => hash('sha256', $prompt),
            'files' => $filePaths,
            'test_path' => $testPath,
            'needles' => [],
            'concepts' => [],
        ];

        try {
            $version = $this->versions->current();
        } catch (Throwable $exception) {
            return new ContextPack(
                'unavailable',
                null,
                $queryBase,
                [],
                $exception->getMessage(),
            );
        }

        [$concepts, $needles, $reasons] = $this->selectConcepts($prompt, $files, $testPath);
        $query = [...$queryBase, 'needles' => $needles, 'concepts' => $concepts];

        if ($concepts === []) {
            return new ContextPack(
                'empty',
                $version,
                $query,
                [],
                'No useful Laravel graph concepts matched the task inputs. Queue context is not injected by default.',
            );
        }

        $items = [];
        foreach ($concepts as $concept) {
            try {
                $result = $this->query->handle($concept, $version, depth: 1, limit: 8);
            } catch (Throwable $exception) {
                return new ContextPack(
                    'unavailable',
                    $version,
                    $query,
                    [],
                    $exception->getMessage(),
                );
            }

            $items[] = [
                'concept' => $concept,
                'selection_reason' => $reasons[$concept],
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
                'provenance' => [
                    'namespace' => $result['namespace'] ?? 'laravel',
                    'version' => $result['version'] ?? $version,
                    'concept' => $concept,
                    'depth' => 1,
                    'limit' => 8,
                ],
            ];
        }

        return new ContextPack(
            'advisory',
            $version,
            $query,
            $items,
            'Bounded Laravel graph context. It cannot change allowed files, the protected test, or completion.',
        );
    }

    /**
     * @param  array<string, string|null>  $files
     * @return array{0: list<string>, 1: list<string>, 2: array<string, string>}
     */
    private function selectConcepts(string $prompt, array $files, string $testPath): array
    {
        $haystack = strtolower($prompt.' '.implode(' ', array_keys($files)).' '.$testPath);
        $candidates = [];
        $needles = [];
        $reasons = [];

        foreach (self::NEEDLES as $needle => $concept) {
            if (! str_contains($haystack, $needle)) {
                continue;
            }
            $needles[] = $needle;
            if (! isset($candidates[$concept])) {
                $candidates[$concept] = true;
                $reasons[$concept] = 'Matched needle "'.$needle.'" in task prompt, allowed files, or test path.';
            } else {
                $reasons[$concept] .= ' Also matched "'.$needle.'".';
            }
        }

        $concepts = array_slice(array_keys($candidates), 0, 3);
        $needles = array_values(array_unique($needles));
        $reasons = array_intersect_key($reasons, array_flip($concepts));

        return [$concepts, $needles, $reasons];
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
}
