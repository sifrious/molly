<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Sifrious\Molly\Projects\MollyProject;
use Sifrious\Molly\Projects\ProjectRegistry;

/**
 * Create a new Laravel application and initialize Molly inside it.
 *
 * Uses InitializeMollyInExistingProject so CLI and Bloom share one attach path.
 */
final class CreateMollyProject
{
    public function __construct(
        private InitializeMollyInExistingProject $initialize = new InitializeMollyInExistingProject,
        private ProjectRegistry $registry = new ProjectRegistry,
    ) {}

    /**
     * @param  (callable(string, string): void)|null  $progress
     * @return array{project: MollyProject, created: array<string, bool>, steps: list<string>}
     */
    public function handle(
        string $path,
        ?string $name = null,
        bool $force = false,
        ?callable $progress = null,
        bool $runComposer = true,
        bool $runMigrations = true,
    ): array {
        $progress ??= static function (string $step, string $message): void {};
        $steps = [];
        $note = function (string $step, string $message) use (&$steps, $progress): void {
            $steps[] = $step.': '.$message;
            $progress($step, $message);
        };

        $path = $this->expand($path);
        $name ??= basename($path);
        $note('path', 'Target directory '.$path);

        if (file_exists($path)) {
            if (! is_dir($path)) {
                throw new RuntimeException('PROJECT_PATH_INVALID: Target exists and is not a directory.');
            }
            if ($this->directoryNotEmpty($path)) {
                if (! $force) {
                    throw new RuntimeException('PROJECT_PATH_NOT_EMPTY: Choose an empty directory or pass --force.');
                }
                $note('force', 'Removing non-empty target because --force was set');
                File::deleteDirectory($path);
            }
        }

        if ($runComposer) {
            $note('laravel', 'Creating Laravel application with Composer');
            $parent = dirname($path);
            File::ensureDirectoryExists($parent);
            $result = Process::path($parent)->timeout(900)->run([
                'composer', 'create-project', 'laravel/laravel', basename($path), '--no-interaction',
            ]);
            if (! $result->successful()) {
                throw new RuntimeException('LARAVEL_CREATE_FAILED: '.trim($result->errorOutput().$result->output()));
            }
        } else {
            $note('laravel', 'Skipped Composer create-project (test/scaffold mode)');
            File::ensureDirectoryExists($path);
            if (! is_file($path.'/artisan')) {
                File::put($path.'/artisan', "#!/usr/bin/env php\n<?php\n// scaffold\n");
            }
            if (! is_file($path.'/composer.json')) {
                File::put($path.'/composer.json', json_encode([
                    'name' => 'molly/scaffold',
                    'require' => ['laravel/framework' => '^12.0'],
                ], JSON_PRETTY_PRINT)."\n");
            }
        }

        $result = $this->initialize->handle(
            path: $path,
            name: $name,
            runComposerRequire: $runComposer,
            runMigrations: $runMigrations,
            progress: function (string $step, string $message) use ($note): void {
                $note($step, $message);
            },
        );

        $project = new MollyProject(
            id: $result['project']->id,
            name: $name,
            path: $result['project']->path,
            source: 'new',
            createdAt: $result['project']->createdAt,
        );
        $this->registry->writeProject($project);
        $note('source', 'Recorded project source as new');

        return [
            'project' => $project,
            'created' => $result['created'],
            'steps' => $steps,
        ];
    }

    private function expand(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '');
            $path = rtrim(str_replace('\\', '/', $home), '/').'/'.substr($path, 2);
        }

        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function directoryNotEmpty(string $path): bool
    {
        $items = @scandir($path);
        if ($items === false) {
            return true;
        }

        return count(array_diff($items, ['.', '..'])) > 0;
    }
}
