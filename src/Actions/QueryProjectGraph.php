<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\GraphQuery;
use Sifrious\Molly\Workspace;

class QueryProjectGraph
{
    public function __construct(private Graph $graph) {}

    /**
     * @param  list<string>  $relations
     * @return array<string, mixed>
     */
    public function handle(string $concept, string $workspace, int $depth = 2, int $limit = 20, array $relations = []): array
    {
        $root = (new Workspace($workspace))->path;

        return $this->graph->query(new GraphQuery('project', substr(hash('sha256', $root), 0, 12), $concept, $depth, $limit, $relations))->toArray();
    }
}
