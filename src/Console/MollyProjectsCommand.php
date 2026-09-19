<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\ListMollyProjects;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyProjectsCommand extends Command
{
    protected $signature = 'molly:projects {--json : Print JSON only}';

    protected $description = 'List Molly projects from the shared registry (same index Bloom reads)';

    public function handle(ListMollyProjects $action): int
    {
        try {
            $projects = array_map(fn ($project) => $project->toArray(), $action->handle());
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'ok', 'projects' => $projects], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                if ($projects === []) {
                    note('No Molly projects registered yet.');
                } else {
                    foreach ($projects as $project) {
                        $this->line($project['name'].'  '.$project['path'].'  ('.$project['source'].')');
                    }
                }
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
