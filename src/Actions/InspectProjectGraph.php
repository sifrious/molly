<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Knowledge\Graph;

class InspectProjectGraph
{
    public function __construct(
        private IndexProjectGraph $index,
        private Graph $graph,
    ) {}

    /**
     * @return array{
     *     workspace: string,
     *     namespace: string,
     *     version: string,
     *     database: string,
     *     sources: int,
     *     nodes: list<array<string, mixed>>,
     *     edges: list<array<string, mixed>>,
     *     blockers: list<array<string, mixed>>,
     *     truncated: bool
     * }
     */
    public function handle(string $workspace): array
    {
        $indexed = $this->index->handle($workspace);
        $overview = $this->graph->overview($indexed['namespace'], $indexed['version']);
        $blockers = array_values(array_filter(
            $overview['nodes'],
            fn (array $node): bool => $node['type'] === 'blocker',
        ));

        return [
            'workspace' => $indexed['workspace'],
            'namespace' => $indexed['namespace'],
            'version' => $indexed['version'],
            'database' => $indexed['database'],
            'sources' => $indexed['sources'],
            'nodes' => $overview['nodes'],
            'edges' => $overview['edges'],
            'blockers' => $blockers,
            'truncated' => $overview['truncated'],
        ];
    }
}
