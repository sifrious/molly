<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\IndexProjectGraph;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

final class MollyProjectIndexCommand extends Command
{
    protected $signature = 'molly:project:index {--workspace= : Workspace path} {--json : Print JSON only}';

    protected $description = 'Rebuild the local project graph from saved tasks and attempts';

    public function handle(IndexProjectGraph $index): int
    {
        try {
            $result = $index->handle((string) ($this->option('workspace') ?: base_path()));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                note('Project graph indexed for '.$result['workspace'].'.');
                table(['Sources', 'Nodes', 'Edges'], [[$result['sources'], $result['nodes'], $result['edges']]]);
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
