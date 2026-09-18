<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\TarpitGraph;

final class IndexTarpitKnowledge
{
    public function __construct(
        private Graph $graph,
        private TarpitGraph $tarpit,
    ) {}

    /** @return array<string, mixed> */
    public function handle(): array
    {
        $snapshot = $this->tarpit->build();
        $counts = $this->graph->replace('tarpit', $snapshot['version'], $snapshot['sources'], $snapshot['nodes'], $snapshot['edges']);

        return [
            'namespace' => 'tarpit',
            'version' => $snapshot['version'],
            'database' => $this->graph->path(),
            ...$counts,
        ];
    }
}
