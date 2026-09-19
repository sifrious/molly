<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\InitializeMollyInExistingProject;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyProjectInitCommand extends Command
{
    protected $signature = 'molly:project-init
        {path? : Existing Laravel project root (default: current app)}
        {--name= : Display name}
        {--no-composer : Do not run composer require}
        {--no-migrate : Skip migrations}
        {--no-graphs : Skip knowledge graph bootstrap}
        {--json : Print JSON only}';

    protected $description = 'Add Molly to an existing Laravel project without overwriting unrelated config';

    public function handle(InitializeMollyInExistingProject $action): int
    {
        try {
            $path = $this->argument('path');
            $path = is_string($path) && trim($path) !== '' ? $path : base_path();

            $result = $action->handle(
                path: $path,
                name: $this->option('name') !== null ? (string) $this->option('name') : null,
                runComposerRequire: ! $this->option('no-composer'),
                runMigrations: ! $this->option('no-migrate'),
                bootstrapGraphs: ! $this->option('no-graphs'),
                progress: $this->option('json') ? null : function (string $step, string $message): void {
                    note('['.$step.'] '.$message);
                },
            );

            $payload = [
                'status' => 'initialized',
                'project' => $result['project']->toArray(),
                'created' => $result['created'],
                'steps' => $result['steps'],
                'graphs' => $result['graphs'],
            ];

            if ($this->option('json')) {
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                note('Molly is initialized in '.$result['project']->path);
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
