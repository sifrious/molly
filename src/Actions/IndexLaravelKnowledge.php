<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\LaravelQueueGraph;
use Sifrious\Molly\Knowledge\LaravelVersion;

final class IndexLaravelKnowledge
{
    public function __construct(
        private Graph $graph,
        private LaravelQueueGraph $queues,
        private LaravelVersion $versions,
    ) {}

    /** @return array<string, mixed> */
    public function handle(?string $requestedVersion = null): array
    {
        $version = $this->versions->current($requestedVersion);
        $snapshot = $this->queues->build($version);
        $counts = $this->graph->replace('laravel', $version, $snapshot['sources'], $snapshot['nodes'], $snapshot['edges']);

        return ['namespace' => 'laravel', 'version' => $version, 'database' => $this->graph->path(), ...$counts];
    }
}
