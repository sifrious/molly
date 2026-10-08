<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Sifrious\Molly\Projects\MollyProject;
use Sifrious\Molly\Projects\ProjectRegistry;
use Sifrious\Molly\Workspace\Directory;
use Sifrious\Molly\Workspace\GitBinary;
use Sifrious\Molly\Workspace\ObserveCheckout;
use Throwable;

/**
 * Attach Molly to an existing Laravel application without clobbering unrelated config.
 *
 * Safe behaviour:
 * - Refuses a workspace that is not a Git repository with at least one commit, before any write
 * - Creates `.molly/` and project metadata when missing
 * - Appends `.molly/` to `.gitignore` only when absent
 * - Publishes `config/molly.php` only when the destination file does not exist
 * - Does not rewrite `.env` agent settings (that remains `molly:setup`)
 * - Registers the path in the shared `~/.molly/projects.json` index Bloom also reads,
 *   and only after migrations and the graph bootstrap succeed
 * - Requires a tagged release constraint, never a development branch
 */
final class InitializeMollyInExistingProject
{
    /** The tagged release line the initializer installs. Keep in step with bin/molly-demo. */
    public const RELEASE_CONSTRAINT = '^0.2';

    /** Public VCS source used until sifrious/molly is listed on Packagist. */
    public const REPOSITORY_URL = 'https://github.com/sifrious/molly';

    public function __construct(
        private ProjectRegistry $registry,
        private BootstrapProjectKnowledgeGraphs $bootstrapGraphs,
        private ObserveCheckout $observe = new ObserveCheckout,
    ) {}

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
        GitBinary::require();
        // The project identity is bound to a commit, so refuse before writing anything, like molly:create.
        $this->observe->requireCommit($root);
        $note('validate', 'Laravel application root accepted at '.$root);

        if ($runComposerRequire && ! $this->packageInstalled($root)) {
            $note('composer', 'Requiring sifrious/molly via Composer');
            $this->composerRequire($root);
        } else {
            $note('composer', $this->packageInstalled($root)
                ? 'Molly package already present'
                : 'Skipped Composer require');
        }

        try {
            $ignored = $this->ensureGitignored($root);
        } catch (Throwable $exception) {
            throw new RuntimeException('GITIGNORE_UNWRITABLE: Molly could not add .molly/ and /storage/molly/ to '.$root.'/.gitignore'.$this->reason($exception).'. Check free disk space and that the file is writable.', 0, $exception);
        }
        $createdGitignore = $ignored !== [];
        $note('gitignore', $createdGitignore ? 'Added '.implode(' and ', $ignored).' to .gitignore' : '.molly/ and /storage/molly/ already ignored');

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

        $graphs = null;
        if ($bootstrapGraphs) {
            $note('graphs', 'Bootstrapping version-pinned knowledge graphs');
            $graphs = $this->bootstrapGraphs->handle(
                $root,
                function (string $step, string $message) use ($note): void {
                    $note('graphs:'.$step, $message);
                },
            );
            $note(
                'graphs',
                $graphs['ok']
                    ? 'Knowledge graphs ready (Laravel '.$graphs['laravel_exact'].')'
                    : 'Knowledge graphs finished with retryable failures; see .molly/graphs/manifest.json',
            );
        } else {
            $note('graphs', 'Skipped knowledge graph bootstrap');
        }

        // Register only after every step succeeded, so a failed init never leaves a
        // project listed as ready. A bootstrap failure throws before this point.
        $existing = $this->registry->readProject($root);
        $createdMetadata = false;
        if ($existing === null) {
            $project = new MollyProject(
                // Reuse the checkout identity so tasks created before init keep their project ID.
                id: $this->registry->checkoutIdentity($root)['project_id'],
                name: $name ?: basename($root),
                path: $root,
                source: $this->registry->takePendingNew($root) ? 'new' : 'existing',
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
            throw new RuntimeException('PROJECT_PATH_INVALID: '.$path.(file_exists($path) ? ' is not a directory' : ' does not exist').'. Choose an existing Laravel project directory.');
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

    /**
     * Composer lists a package in vendor/composer/installed.json after extracting it, then writes the
     * autoload map. A composer require killed partway can leave vendor/sifrious/molly behind without
     * either, so the rerun runs composer require again unless both name Molly.
     */
    private function packageInstalled(string $root): bool
    {
        $installed = json_decode((string) @file_get_contents($root.'/vendor/composer/installed.json'), true);
        $packages = is_array($installed) ? ($installed['packages'] ?? $installed) : [];
        $names = array_map(fn (mixed $package): mixed => is_array($package) ? ($package['name'] ?? null) : null, is_array($packages) ? $packages : []);

        return in_array('sifrious/molly', $names, true)
            && str_contains((string) @file_get_contents($root.'/vendor/composer/autoload_psr4.php'), "/sifrious/molly/src'");
    }

    private function composerRequire(string $root): void
    {
        // A failed step, such as an authentication error in composer require, puts composer.json
        // and composer.lock back byte for byte, so no repositories entry is left behind.
        $saved = $this->composerFiles($root);
        try {
            if (! $this->hasMollyRepository($root)) {
                $this->composer($root, ['config', 'repositories.molly', 'vcs', self::REPOSITORY_URL]);
            }

            $this->composer($root, ['require', '--dev', 'sifrious/molly:'.self::RELEASE_CONSTRAINT, '--no-interaction']);
        } catch (Throwable $exception) {
            $this->restoreComposerFiles($root, $saved);
            throw $exception;
        }
    }

    /** @return array<string, ?string> composer.json and composer.lock contents, null when absent */
    private function composerFiles(string $root): array
    {
        $files = [];
        foreach (['composer.json', 'composer.lock'] as $name) {
            $files[$name] = is_file($root.'/'.$name) ? File::get($root.'/'.$name) : null;
        }

        return $files;
    }

    /** @param  array<string, ?string>  $saved */
    private function restoreComposerFiles(string $root, array $saved): void
    {
        foreach ($saved as $name => $contents) {
            $path = $root.'/'.$name;
            if ($contents === null) {
                File::delete($path);
            } elseif (! is_file($path) || File::get($path) !== $contents) {
                File::put($path, $contents);
            }
        }
    }

    /** @param  list<string>  $arguments */
    private function composer(string $root, array $arguments): void
    {
        $result = Process::path($root)->timeout(600)->run(['composer', ...$arguments]);
        if (! $result->successful()) {
            throw new RuntimeException('COMPOSER_REQUIRE_FAILED: '.$this->processError($result->errorOutput().$result->output()));
        }
    }

    private function hasMollyRepository(string $root): bool
    {
        $composer = json_decode(File::get($root.'/composer.json'), true) ?: [];
        foreach ((array) ($composer['repositories'] ?? []) as $repository) {
            if (is_array($repository) && str_contains((string) ($repository['url'] ?? ''), 'github.com/sifrious/molly')) {
                return true;
            }
        }

        return false;
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

        Directory::ensure($root.'/config');
        // Write a staged copy and rename it into place, so an interrupted init never leaves a
        // truncated config/molly.php that the rerun would keep because the file exists.
        Directory::replaceFile($destination, File::get($stub), 'CONFIG_UNWRITABLE', 0644);

        return true;
    }

    private function migrate(string $root): void
    {
        if ($this->sameApplication($root)) {
            Artisan::call('migrate', ['--force' => true]);

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

    /**
     * Ignore Molly's local files as the README asks: .molly/ and /storage/molly/. A line
     * that already covers one, such as /.molly or /storage/, counts, and nothing is added
     * twice. Returns the lines Molly added.
     *
     * @return list<string>
     */
    private function ensureGitignored(string $root): array
    {
        $gitignore = $root.'/.gitignore';
        $contents = is_file($gitignore) ? File::get($gitignore) : '';
        $covered = [
            '.molly/' => '/\A\/?\.molly\/?\z/',
            '/storage/molly/' => '/\A\/?storage(\/molly)?\/?\z/',
        ];
        $lines = array_map(trim(...), preg_split('/\R/', $contents) ?: []);
        $missing = array_keys(array_filter($covered, fn (string $pattern): bool => preg_grep($pattern, $lines) === []));
        if ($missing === []) {
            return [];
        }

        // Append, so an interrupted write never loses the lines already there.
        File::append($gitignore, ($contents === '' || str_ends_with($contents, "\n") ? '' : "\n").implode("\n", $missing)."\n");

        return $missing;
    }

    /** PHP's reason without the function name and path, which the message already names. */
    private function reason(Throwable $exception): string
    {
        return preg_match('/\): (?:Failed to open stream: )?(.+)\z/', $exception->getMessage(), $match) === 1 ? ' ('.$match[1].')' : '';
    }

    private function processError(string $output): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $output) ?? $output);

        return $line !== '' ? $line : 'Composer or Artisan exited with an error.';
    }
}
