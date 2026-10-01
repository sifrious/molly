<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Laravel\Prompts\Prompt;
use Sifrious\Molly\Actions\InitializeMollyInExistingProject;
use Throwable;

use function Laravel\Prompts\note;

class MollyProjectInitCommand extends Command
{
    use ReportsFailures;

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
                    $this->printNote('['.$step.'] '.$message);
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
                $this->writeJson($payload);
            } else {
                $this->printNote('Molly is initialized in '.$result['project']->path);
                $this->printNote('List projects: php artisan molly:projects');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Prompt::setOutput($this->output);

            return $this->reportFailure($exception->getMessage(), ['status' => 'error', 'error' => $exception->getMessage()]);
        }
    }

    /**
     * Initializing the application this command runs in migrates through Artisan::call, which
     * leaves Prompts writing to that call's buffer, so every later note was lost. Point Prompts
     * back at this command before each note.
     */
    private function printNote(string $message): void
    {
        Prompt::setOutput($this->output);
        note($message);
    }
}
