<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Sifrious\Molly\Projects\MollyProject;
use Sifrious\Molly\Projects\ProjectRegistry;
use Sifrious\Molly\Workspace\Directory;
use Sifrious\Molly\Workspace\GitBinary;
use Sifrious\Molly\Workspace\ObserveCheckout;

/**
 * Create a new Laravel application and initialize Molly inside it.
 *
 * Uses InitializeMollyInExistingProject so CLI and Bloom share one attach path.
 */
final class CreateMollyProject
{
    public function __construct(
        private InitializeMollyInExistingProject $initialize,
        private ProjectRegistry $registry,
        private ObserveCheckout $observe = new ObserveCheckout,
    ) {}

    /**
     * A new application is not a Git repository, and Molly never creates one or commits for the
     * user. When the app is not in a committed checkout, stop after creating it and return
     * status needs_commit with the reason and the commands to run; the project is attached by
     * molly:project-init after the user commits it. Inside an existing repository the commands
     * commit the app there and never run git init. Molly attaches right away only when the HEAD
     * commit already contains files under the target, such as a tracked directory replaced with --force.
     *
     * @param  (callable(string, string): void)|null  $progress
     * @return array{status: 'created'|'needs_commit', path: string, project: ?MollyProject, created: array<string, bool>, steps: list<string>, reason: ?string, next: list<string>}
     */
    public function handle(
        string $path,
        ?string $name = null,
        bool $force = false,
        ?callable $progress = null,
        bool $runComposer = true,
        bool $runMigrations = true,
        bool $bootstrapGraphs = true,
    ): array {
        $progress ??= static function (string $step, string $message): void {};
        $steps = [];
        $note = function (string $step, string $message) use (&$steps, $progress): void {
            $steps[] = $step.': '.$message;
            $progress($step, $message);
        };

        $path = $this->expand($path);
        GitBinary::require();
        $name ??= basename($path);
        $note('path', 'Target directory '.$path);

        if (file_exists($path)) {
            if (! is_dir($path)) {
                throw new RuntimeException('PROJECT_PATH_INVALID: '.$path.' exists and is not a directory. Choose a new or empty directory.');
            }
            if ($this->directoryNotEmpty($path)) {
                if (! $force) {
                    throw new RuntimeException('PROJECT_PATH_NOT_EMPTY: '.$path.' is not empty. Choose an empty directory, or pass --force to delete it and create the application again, for example after an interrupted molly:project-new.');
                }
                $note('force', 'Removing non-empty target because --force was set');
                File::deleteDirectory($path);
            }
        }

        if ($runComposer) {
            $note('laravel', 'Creating Laravel application with Composer');
            $parent = dirname($path);
            Directory::ensure($parent);
            $result = Process::path($parent)->timeout(900)->run([
                'composer', 'create-project', 'laravel/laravel', basename($path), '--no-interaction',
            ]);
            if (! $result->successful()) {
                throw new RuntimeException('LARAVEL_CREATE_FAILED: '.trim($result->errorOutput().$result->output()));
            }
        } else {
            $note('laravel', 'Skipped Composer create-project (test/scaffold mode)');
            Directory::ensure($path);
            if (! is_file($path.'/artisan')) {
                File::put($path.'/artisan', "#!/usr/bin/env php\n<?php\n// scaffold\n");
            }
            if (! is_file($path.'/composer.json')) {
                File::put($path.'/composer.json', json_encode([
                    'name' => 'molly/scaffold',
                    'require' => ['laravel/framework' => '^12.0'],
                ], JSON_PRETTY_PRINT)."\n");
            }
            if (! is_file($path.'/composer.lock')) {
                File::put($path.'/composer.lock', json_encode([
                    'packages' => [[
                        'name' => 'laravel/framework',
                        'version' => 'v12.0.0',
                    ]],
                    'packages-dev' => [],
                ], JSON_PRETTY_PRINT).'
');
            }
        }

        $location = ObserveCheckout::locate($path);
        if ($location['root'] === null || $this->observe->head($path) === null || ! $this->observe->headContainsFiles($path)) {
            $note('git', 'Laravel application created. Molly did not attach it because it is not in a Git commit yet.');

            return [
                'status' => 'needs_commit',
                'path' => $path,
                'project' => null,
                'created' => ['application' => true, 'metadata' => false],
                'steps' => $steps,
                'reason' => $this->commitReason($path, $location),
                'next' => [...$this->commitCommands($path, $location), $this->initCommand($path, $name)],
            ];
        }

        $result = $this->initialize->handle(
            path: $path,
            name: $name,
            runComposerRequire: $runComposer,
            runMigrations: $runMigrations,
            bootstrapGraphs: $bootstrapGraphs,
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
            'status' => 'created',
            'path' => $project->path,
            'project' => $project,
            'created' => $result['created'],
            'steps' => $steps,
            'reason' => null,
            'next' => [],
        ];
    }

    /**
     * Inside an existing repository, including one that ignores the path, Molly never suggests
     * git init: the user commits the app into that repository instead.
     *
     * @param  array{root: ?string, ignored_by: ?string}  $location
     */
    private function commitReason(string $path, array $location): string
    {
        if ($location['ignored_by'] !== null) {
            return $path.' is ignored by the Git repository at '.$location['ignored_by'].', so its files cannot be committed. Stop ignoring it in '.$location['ignored_by'].', then commit it.';
        }
        if ($location['root'] !== null) {
            return $path.' is inside the Git repository at '.$location['root'].', which has no commit with its files yet. Commit it in that repository.';
        }

        return $path.' is not in a Git repository. Create a repository for it and commit it.';
    }

    /**
     * @param  array{root: ?string, ignored_by: ?string}  $location
     * @return list<string>
     */
    private function commitCommands(string $path, array $location): array
    {
        $repository = $location['root'] ?? $location['ignored_by'];
        if ($repository !== null) {
            return ObserveCheckout::commitCommands($repository, $path);
        }

        return [
            'git -C '.escapeshellarg($path).' init',
            'git -C '.escapeshellarg($path).' add -A',
            'git -C '.escapeshellarg($path).' commit -m "Start"',
        ];
    }

    private function initCommand(string $path, string $name): string
    {
        return 'php artisan molly:project-init '.escapeshellarg($path).($name !== basename($path) ? ' --name='.escapeshellarg($name) : '');
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
