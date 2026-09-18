<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphQuery;
use Sifrious\Molly\Knowledge\LaravelVersion;
use Sifrious\Molly\Knowledge\TarpitGraph;

final class QueryKnowledgeGraph
{
    public function __construct(
        private Graph $graph,
        private LaravelVersion $versions,
        private TarpitGraph $tarpit,
    ) {}

    /**
     * @param  list<string>  $relations
     * @return array<string, mixed>
     */
    public function handle(string $concept, ?string $requestedVersion = null, int $depth = 2, int $limit = 20, array $relations = [], string $namespace = 'laravel'): array
    {
        $namespace = $namespace === '' ? 'laravel' : $namespace;
        $version = match ($namespace) {
            'laravel' => $this->versions->current($requestedVersion),
            'nativephp' => $this->nativephpVersion($requestedVersion),
            'tarpit' => $this->tarpitVersion($requestedVersion),
            default => throw new RuntimeException('KNOWLEDGE_NAMESPACE_INVALID: Choose laravel, nativephp, or tarpit.'),
        };

        return $this->graph->query(new GraphQuery($namespace, $version, $concept, $depth, $limit, $relations))->toArray();
    }

    private function nativephpVersion(?string $requested): string
    {
        if ($requested === null || $requested === '') {
            throw new RuntimeException('KNOWLEDGE_VERSION_REQUIRED: NativePHP queries need desktop-2 or mobile-4.');
        }

        if (! in_array($requested, ['desktop-2', 'mobile-4'], true)) {
            throw new RuntimeException('KNOWLEDGE_VERSION_INVALID: NativePHP versions are desktop-2 and mobile-4.');
        }

        return $requested;
    }

    private function tarpitVersion(?string $requested): string
    {
        $version = $this->tarpit->version();
        if ($requested !== null && $requested !== '' && $requested !== $version) {
            throw new RuntimeException("KNOWLEDGE_VERSION_MISMATCH: Tarpit notes {$version} are bundled, not {$requested}.");
        }

        return $version;
    }
}
