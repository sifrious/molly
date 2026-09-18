<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Sifrious\Molly\Actions\IndexLaravelKnowledge;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

final class MollyKnowledgeIndexCommand extends Command
{
    protected $signature = 'molly:knowledge:index {namespace=laravel : Knowledge namespace} {--laravel-version= : Installed Laravel major version} {--json : Print JSON only}';

    protected $description = 'Build the local Laravel knowledge graph';

    public function handle(IndexLaravelKnowledge $index): int
    {
        try {
            if ($this->argument('namespace') !== 'laravel') {
                throw new RuntimeException('KNOWLEDGE_NAMESPACE_INVALID: The first release supports the laravel namespace.');
            }

            $result = $index->handle($this->option('laravel-version'));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                note('Laravel '.$result['version'].' queue, routing, testing, and validation knowledge indexed.');
                table(['Sources', 'Nodes', 'Edges'], [[$result['sources'], $result['nodes'], $result['edges']]]);
                note('Database: '.$result['database']);
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
