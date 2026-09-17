<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphQuery;
use Sifrious\Molly\Knowledge\LaravelVersion;

final class QueryKnowledgeGraph
{
    public function __construct(private Graph $graph, private LaravelVersion $versions) {}

    /**
     * @param  list<string>  $relations
     * @return array<string, mixed>
     */
    public function handle(string $concept, ?string $requestedVersion = null, int $depth = 2, int $limit = 20, array $relations = []): array
    {
        $version = $this->versions->current($requestedVersion);

        return $this->graph->query(new GraphQuery('laravel', $version, $concept, $depth, $limit, $relations))->toArray();
    }
}
