<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\QueryProjectGraph;
use Throwable;

use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

final class MollyProjectQueryCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:project:query {concept : Task, test, file, run, or blocker to find} {--workspace= : Workspace path} {--depth=2 : Relationship depth from 0 through 3} {--limit=20 : Node limit from 1 through 40} {--relation=* : Include only these relationships} {--json : Print JSON only}';

    protected $description = 'Read a bounded project graph neighborhood';

    public function handle(QueryProjectGraph $query): int
    {
        try {
            $result = $query->handle(
                (string) $this->argument('concept'),
                (string) ($this->option('workspace') ?: base_path()),
                filter_var($this->option('depth'), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? -1,
                filter_var($this->option('limit'), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? 0,
                array_values($this->option('relation')),
            );
            if ($this->option('json')) {
                $this->writeJson($result);

                return self::SUCCESS;
            }

            if (($result['freshness']['stale'] ?? false) === true) {
                warning('This graph is stale ('.implode(', ', $result['freshness']['reasons']).'). Run '.$result['freshness']['fix'].' to rebuild it.');
            }

            if ($result['nodes'] === []) {
                note('No project knowledge matched "'.$result['concept'].'".');

                return self::SUCCESS;
            }

            table(['Type', 'Name'], array_map(fn (array $node): array => [$node['type'], $node['label']], $result['nodes']));
            $labels = array_column($result['nodes'], 'label', 'id');
            table(['Relationship', 'From', 'To'], array_map(fn (array $edge): array => [
                $edge['relation'], $labels[$edge['from']] ?? $edge['from'], $labels[$edge['to']] ?? $edge['to'],
            ], $result['edges']));
            if ($result['truncated']) {
                note('The result reached its node limit. Raise --limit up to 40 or narrow the relationship filter.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage());
        }
    }
}
