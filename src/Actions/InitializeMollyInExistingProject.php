<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Sifrious\Molly\Projects\MollyProject;
use Sifrious\Molly\Projects\ProjectRegistry;
use Sifrious\Molly\Actions\BootstrapProjectKnowledgeGraphs;

/**
 * Attach Molly to an existing Laravel application without clobbering unrelated config.
 *
 * Safe behaviour:
 * - Creates `.molly/` and project metadata when missing
 * - Appends `.molly/` to `.gitignore` only when absent
 * - Publishes `config/molly.php` only when the destination file does not exist
 * - Does not rewrite `.env` agent settings (that remains `molly:setup`)
 * - Registers the path in the shared `~/.molly/projects.json` index Bloom also reads
 */
final class InitializeMollyInExistingProject
{
    public function __construct(private ProjectRegistry $registry = new ProjectRegistry) {}

    /**
     * @param  (callable(string, string): void)|null  $progress
     * @return array{project: MollyProject, created: array{metadata: bool, gitignore: bool, config: bool, migrated: bool}, steps: list<string>, graphs: ?array{ok: bool, manifest_path: string, units: list<array<string, mixed>>, laravel_exact: string}}
     */
    public function handle(
        string $path,
        ?string $name = null,
        bool $runComposerRequire = true,
        bool $runMigrations = true,
        bool $bootstrapGraphs = true,
        ?callable $progress = null,
    ): array {
        $progress ??= static function (string $step, string $message): void {};
        $steps = [];
        $note = function (string $step, string $message) use (&$steps, $progress): void {
            $steps[] = $step.': '.$message;
            $progress($step, $message);
        };

        $root = $this->assertLaravelRoot($path);
        $note('validate', 'Laravel application root accepted at '.$root);

        if ($runComposerRequire && ! $this->packageInstalled($root)) {
            $note('composer', 'Requiring sifrious/molly via Composer');
            $this->composerRequire($root);
        } else {
            $note('composer', $this->packageInstalled($root)
                ? 'Molly package already present'
                : 'Skipped Composer require');
        }

        $createdGitignore = $this->ensureGitignored($root);
        $note('gitignore', $createdGitignore ? 'Added .molly/ to .gitignore' : '.molly/ already ignored');

        $createdConfig = false;
        if (! is_file($root.'/config/molly.php')) {
            $note('config', 'Publishing Molly config (destination did not exist)');
            $createdConfig = $this->publishConfig($root);
        } else {
            $note('config', 'Left existing config/molly.php unchanged');
        }

        $migrated = false;
        if ($runMigrations) {
            $note('migrate', 'Running migrations');
            $this->migrate($root);
            $migrated = true;
        } else {
            $note('migrate', 'Skipped migrations');
        }

        $existing = $this->registry->readProject($root);
        $createdMetadata = false;
        if ($existing === null) {
            $project = new MollyProject(
                id: $this->registry->makeId(),
                name: $name ?: basename($root),
                path: $root,
                source: 'existing',
                createdAt: gmdate('c'),
            );
            $this->registry->writeProject($project);
            $createdMetadata = true;
            $note('register', 'Wrote .molly/project.json and registered globally');
        } else {
            $project = $existing;
            if ($name !== null && $name !== '' && $name !== $existing->name) {
                $project = new MollyProject(
                    id: $existing->id,
                    name: $name,
                    path: $root,
                    source: $existing->source,
                    createdAt: $existing->createdAt,
                );
                $this->registry->writeProject($project);
                $note('register', 'Updated project display name');
            } else {
                $this->registry->registerPath($root);
                $note('register', 'Project already initialized; refreshed global index');
            }
        }

        $graphs = null;
        if ($bootstrapGraphs) {
            $note('graphs', 'Bootstrapping version-pinned knowledge graphs');
            $graphs = (new BootstrapProjectKnowledgeGraphs)->handle(
                $root,
                function (string $step, string $message) use ($note): void {
                    $note('graphs:'.$step, $message);
                },
            );
            $note(
                'graphs',
                $graphs['ok']
                    ? 'Knowledge graphs ready (Laravel '.$graphs['laravel_exact'].')'
                    : 'Knowledge graphs finished with retryable failures — see .molly/graphs/manifest.json',
            );
        } else {
            $note('graphs', 'Skipped knowledge graph bootstrap');
        }

        return [
            'project' => $project,
            'created' => [
                'metadata' => $createdMetadata,
                'gitignore' => $createdGitignore,
                'config' => $createdConfig,
                'migrated' => $migrated,
            ],
            'steps' => $steps,
            'graphs' => $graphs,
        ];
    }

    private function assertLaravelRoot(string $path): string
    {
        $root = realpath($path);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('PROJECT_PATH_INVALID: Choose an existing Laravel project directory.');
        }

        if (! is_file($root.'/artisan') || ! is_file($root.'/composer.json')) {
            throw new RuntimeException('PROJECT_NOT_LARAVEL: Molly needs an artisan file and composer.json in the project root.');
        }

        try {
            /** @var array<string, mixed> $composer */
            $composer = json_decode(File::get($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('PROJECT_NOT_LARAVEL: composer.json is not valid JSON.');
        }

        $requires = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);
        if (! isset($requires['laravel/framework'])) {
            throw new RuntimeException('PROJECT_NOT_LARAVEL: composer.json must require laravel/framework.');
        }

        return str_replace('\\', '/', $root);
    }

    private function packageInstalled(string $root): bool
    {
        return is_dir($root.'/vendor/sifrious/molly');
    }

    private function composerRequire(string $root): void
    {
        $result = Process::path($root)->timeout(600)->run([
            'composer', 'require', '--dev', 'sifrious/molly:dev-main', '--no-interaction',
        ]);
        if (! $result->successful()) {
            throw new RuntimeException('COMPOSER_REQUIRE_FAILED: '.$this->processError($result->errorOutput().$result->output()));
        }
    }

    private function publishConfig(string $root): bool
    {
        $destination = $root.'/config/molly.php';
        if (is_file($destination)) {
            return false;
        }

        $stub = dirname(__DIR__, 2).'/config/molly.php';
        if (! is_file($stub)) {
            throw new RuntimeException('CONFIG_STUB_MISSING: Packaged config/molly.php was not found.');
        }

        File::ensureDirectoryExists($root.'/config');
        File::copy($stub, $destination);

        return true;
    }

    private function migrate(string $root): void
    {
        if ($this->sameApplication($root)) {
            \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);

            return;
        }

        $result = Process::path($root)->timeout(300)->run([
            PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction',
        ]);
        if (! $result->successful()) {
            throw new RuntimeException('MIGRATE_FAILED: '.$this->processError($result->errorOutput().$result->output()));
        }
    }

    private function sameApplication(string $root): bool
    {
        $base = realpath(base_path());

        return $base !== false && str_replace('\\', '/', $base) === $root;
    }

    private function ensureGitignored(string $root): bool
    {
        $gitignore = $root.'/.gitignore';
        $needle = '.molly/';
        if (! is_file($gitignore)) {
            File::put($gitignore, $needle.PHP_EOL);

            return true;
        }

        $contents = File::get($gitignore);
        if (preg_match('/(^|\\n)\\s*\\.molly\\/?\\s*($|\\n)/', $contents) === 1) {
            return false;
        }

        File::append($gitignore, (str_ends_with($contents, "\n") ? '' : "\n").$needle."\n");

        return true;
    }

    private function processError(string $output): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $output) ?? $output);

        return $line !== '' ? $line : 'Composer or Artisan exited with an error.';
    }
}
