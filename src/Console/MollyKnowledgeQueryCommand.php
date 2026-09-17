<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\QueryKnowledgeGraph;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

final class MollyKnowledgeQueryCommand extends Command
{
    protected $signature = 'molly:knowledge:query {concept : Concept or symbol to find} {--laravel-version= : Installed Laravel major version} {--depth=2 : Relationship depth from 0 through 3} {--limit=20 : Node limit from 1 through 40} {--relation=* : Include only these relationships} {--json : Print JSON only}';

    protected $description = 'Read a bounded Laravel knowledge neighborhood';

    public function handle(QueryKnowledgeGraph $query): int
    {
        try {
            $result = $query->handle(
                (string) $this->argument('concept'),
                $this->option('laravel-version'),
                filter_var($this->option('depth'), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? -1,
                filter_var($this->option('limit'), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? 0,
                array_values($this->option('relation')),
            );
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            if ($result['nodes'] === []) {
                note('No Laravel '.$result['version'].' knowledge matched "'.$result['concept'].'".');

                return self::SUCCESS;
            }

            table(['Type', 'Name', 'Sources'], array_map(fn (array $node): array => [
                $node['type'], $node['label'], implode(', ', array_column($node['sources'], 'key')),
            ], $result['nodes']));
            $labels = array_column($result['nodes'], 'label', 'id');
            table(['Relationship', 'From', 'To'], array_map(fn (array $edge): array => [
                $edge['relation'], $labels[$edge['from']], $labels[$edge['to']],
            ], $result['edges']));
            if ($result['truncated']) {
                note('The result reached its node limit. Raise --limit up to 40 or narrow the relationship filter.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
