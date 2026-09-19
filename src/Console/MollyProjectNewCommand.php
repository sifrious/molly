<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\CreateMollyProject;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyProjectNewCommand extends Command
{
    protected $signature = 'molly:project-new
        {path? : Directory for the new Laravel + Molly project}
        {--name= : Display name}
        {--force : Replace a non-empty target directory}
        {--no-composer : Skip Composer create-project and require (tests / scaffold)}
        {--no-migrate : Skip migrations}
        {--no-graphs : Skip knowledge graph bootstrap}
        {--json : Print JSON only}';

    protected $description = 'Create a new Laravel project and initialize Molly (same service Bloom uses)';

    public function handle(CreateMollyProject $action): int
    {
        try {
            $path = $this->argument('path');
            if (! is_string($path) || trim($path) === '') {
                throw new \InvalidArgumentException('Provide a target path for the new project.');
            }

            $result = $action->handle(
                path: $path,
                name: $this->option('name') !== null ? (string) $this->option('name') : null,
                force: (bool) $this->option('force'),
                progress: $this->option('json') ? null : function (string $step, string $message): void {
                    note('['.$step.'] '.$message);
                },
                runComposer: ! $this->option('no-composer'),
                runMigrations: ! $this->option('no-migrate'),
                bootstrapGraphs: ! $this->option('no-graphs'),
            );

            $payload = [
                'status' => 'created',
                'project' => $result['project']->toArray(),
                'created' => $result['created'],
                'steps' => $result['steps'],
            ];

            if ($this->option('json')) {
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                note('Molly project ready: '.$result['project']->path);
                note('List projects: php artisan molly:projects');
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
