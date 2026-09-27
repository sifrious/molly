<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;

/** Re-run a single graph bootstrap unit from the project manifest. */
final class RetryProjectKnowledgeGraphUnit
{
    public function __construct(private BootstrapProjectKnowledgeGraphs $bootstrap) {}

    /**
     * @param  (callable(string, string): void)|null  $progress
     * @return array{ok: bool, manifest_path: string, units: list<array<string, mixed>>, laravel_exact: string, retried_unit: string}
     */
    public function handle(string $projectRoot, string $unitId, ?callable $progress = null): array
    {
        $unitId = trim($unitId);
        if ($unitId === '') {
            throw new RuntimeException('GRAPH_UNIT_REQUIRED: Pass a unit id from .molly/graphs/manifest.json (for example laravel:laravel/framework).');
        }

        $valid = $this->bootstrap->unitIds($projectRoot);
        if (! in_array($unitId, $valid, true)) {
            throw new RuntimeException('UNIT_UNKNOWN: This project has no graph unit "'.$unitId.'". Valid units: '.implode(', ', $valid).'.');
        }

        $result = $this->bootstrap->handle($projectRoot, $progress, $unitId);
        $result['retried_unit'] = $unitId;

        return $result;
    }
}
