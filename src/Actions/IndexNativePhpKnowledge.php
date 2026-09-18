<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Knowledge\Graph;
use Sifrious\Molly\Knowledge\NativePhpGraph;

final class IndexNativePhpKnowledge
{
    public function __construct(
        private Graph $graph,
        private NativePhpGraph $nativephp,
    ) {}

    /** @return array<string, mixed> */
    public function handle(): array
    {
        $desktop = $this->nativephp->build('desktop');
        $mobile = $this->nativephp->build('mobile');
        $desktopCounts = $this->graph->replace('nativephp', $desktop['version'], $desktop['sources'], $desktop['nodes'], $desktop['edges']);
        $mobileCounts = $this->graph->replace('nativephp', $mobile['version'], $mobile['sources'], $mobile['nodes'], $mobile['edges']);

        return [
            'namespace' => 'nativephp',
            'versions' => [$desktop['version'], $mobile['version']],
            'sources' => $desktopCounts['sources'] + $mobileCounts['sources'],
            'nodes' => $desktopCounts['nodes'] + $mobileCounts['nodes'],
            'edges' => $desktopCounts['edges'] + $mobileCounts['edges'],
            'database' => $this->graph->path(),
        ];
    }
}
