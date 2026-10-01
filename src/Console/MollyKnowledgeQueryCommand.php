<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Sifrious\Molly\Actions\QueryKnowledgeGraph;
use Throwable;

use function Laravel\Prompts\note;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

final class MollyKnowledgeQueryCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:knowledge:query {concept : Concept or symbol to find} {--namespace=laravel : Knowledge namespace} {--nativephp-version= : NativePHP desktop-2 or mobile-4} {--laravel-version= : Installed Laravel major version} {--depth=2 : Relationship depth from 0 through 3} {--limit=20 : Node limit from 1 through 40} {--relation=* : Include only these relationships} {--workspace= : Project whose graph manifest is checked for staleness (default: current app)} {--json : Print JSON only}';

    protected $description = 'Read a bounded Laravel, NativePHP, or tarpit knowledge neighborhood';

    public function handle(QueryKnowledgeGraph $query): int
    {
        try {
            $result = $query->handle(
                (string) $this->argument('concept'),
                $this->requestedVersion(),
                filter_var($this->option('depth'), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? -1,
                filter_var($this->option('limit'), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? 0,
                array_values($this->option('relation')),
                (string) $this->option('namespace'),
                $this->option('workspace') ? (string) $this->option('workspace') : null,
            );
            if ($this->option('json')) {
                $this->writeJson($result);

                return self::SUCCESS;
            }

            if (($result['freshness']['stale'] ?? false) === true) {
                warning('This graph is stale ('.implode(', ', $result['freshness']['reasons']).'). Run '.$result['freshness']['fix'].' to rebuild it.');
            }

            $label = match ($result['namespace']) {
                'nativephp' => 'NativePHP '.$result['version'],
                'tarpit' => 'Tarpit '.$result['version'],
                default => 'Laravel '.$result['version'],
            };
            if ($result['nodes'] === []) {
                note('No '.$label.' knowledge matched "'.$result['concept'].'".');

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
            return $this->reportFailure($exception->getMessage());
        }
    }

    private function requestedVersion(): ?string
    {
        $namespace = (string) $this->option('namespace');
        $nativephp = $this->option('nativephp-version');
        $laravel = $this->option('laravel-version');
        if ($namespace === 'nativephp') {
            if (filled($laravel)) {
                throw new RuntimeException('KNOWLEDGE_VERSION_INVALID: NativePHP queries use --nativephp-version, not --laravel-version.');
            }

            return filled($nativephp) ? (string) $nativephp : null;
        }
        if ($namespace === 'tarpit') {
            if (filled($nativephp) || filled($laravel)) {
                throw new RuntimeException('KNOWLEDGE_VERSION_INVALID: Tarpit queries do not use --nativephp-version or --laravel-version.');
            }

            return null;
        }
        if (filled($nativephp)) {
            throw new RuntimeException('KNOWLEDGE_VERSION_INVALID: Laravel queries use --laravel-version, not --nativephp-version.');
        }

        return filled($laravel) ? (string) $laravel : null;
    }
}
