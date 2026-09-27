<?php

namespace Sifrious\Molly\Projects;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Sifrious\Molly\Workspace\Directory;

/**
 * Shared Molly project index for CLI and Bloom.
 *
 * Per-project: `<path>/.molly/project.json`
 * Per-checkout identity: `<path>/.molly/identity.json`
 * Global: `~/.molly/projects.json` (JSON array of absolute paths)
 */
final class ProjectRegistry
{
    public function __construct(private ?string $home = null) {}

    public function home(): string
    {
        if ($this->home !== null) {
            return $this->home;
        }

        $env = getenv('MOLLY_HOME');
        if (is_string($env) && $env !== '') {
            return rtrim(str_replace('\\', '/', $env), '/');
        }

        $userHome = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());

        return rtrim(str_replace('\\', '/', $userHome), '/').'/.molly';
    }

    public function globalIndexPath(): string
    {
        return $this->home().'/projects.json';
    }

    public function projectFile(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/').'/.molly/project.json';
    }

    public function makeId(): string
    {
        return (string) Str::uuid();
    }

    public function identityFile(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/').'/.molly/identity.json';
    }

    /**
     * Canonical Molly identities for one checkout root.
     *
     * The IDs are minted once, stored in `.molly/identity.json`, and read back by every
     * later call and process. The path is never the ID. When the checkout is a registered
     * project, `project_id` is the registry ID from `.molly/project.json`.
     *
     * @return array{project_id: string, workspace_id: string, repository_id: string, checkout_id: string}
     */
    public function checkoutIdentity(string $path): array
    {
        $root = $this->normalizePath($path);
        $file = $this->identityFile($root);
        $identity = $this->readIdentity($file) ?? $this->createIdentity($file);

        $project = $this->readProject($root);
        if ($project instanceof MollyProject) {
            $identity['project_id'] = $project->id;
        }

        return $identity;
    }

    public function readProject(string $path): ?MollyProject
    {
        $file = $this->projectFile($path);
        if (! is_file($file)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('PROJECT_RECORD_INVALID: '.$file.' is not valid JSON.');
        }

        $data['path'] = $this->normalizePath($path);

        return MollyProject::fromArray($data);
    }

    public function writeProject(MollyProject $project): void
    {
        $normalized = new MollyProject(
            id: $project->id,
            name: $project->name,
            path: $this->normalizePath($project->path),
            source: $project->source,
            createdAt: $project->createdAt,
        );

        $file = Directory::molly($normalized->path, 'project.json');
        Directory::ensure(dirname($file), 0700);
        $mask = umask(0077);
        try {
            File::put(
                $file,
                json_encode($normalized->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            );
        } finally {
            umask($mask);
        }

        $this->registerPath($normalized->path);
    }

    public function registerPath(string $path): void
    {
        $path = $this->normalizePath($path);
        $paths = $this->indexedPaths();
        if (! in_array($path, $paths, true)) {
            $paths[] = $path;
            sort($paths);
            $this->writeIndex($paths);
        }
    }

    public function unregisterPath(string $path): void
    {
        $path = $this->normalizePath($path);
        $paths = array_values(array_filter(
            $this->indexedPaths(),
            fn (string $candidate): bool => $candidate !== $path
        ));
        $this->writeIndex($paths);
    }

    /** @return list<MollyProject> */
    public function all(): array
    {
        $projects = [];
        foreach ($this->indexedPaths() as $path) {
            if (! is_dir($path)) {
                continue;
            }
            $project = $this->readProject($path);
            if ($project instanceof MollyProject) {
                $projects[] = $project;
            }
        }

        usort($projects, fn (MollyProject $a, MollyProject $b): int => strcmp($a->name, $b->name));

        return $projects;
    }

    /** @return list<string> */
    private function indexedPaths(): array
    {
        $file = $this->globalIndexPath();
        if (! is_file($file)) {
            return [];
        }

        try {
            $data = json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('PROJECT_INDEX_INVALID: '.$file.' is not valid JSON.');
        }

        if (! is_array($data)) {
            throw new RuntimeException('PROJECT_INDEX_INVALID: '.$file.' must be a JSON array of paths.');
        }

        $paths = [];
        foreach ($data as $entry) {
            if (is_string($entry) && $entry !== '') {
                $paths[] = $this->normalizePath($entry);
            }
        }

        return array_values(array_unique($paths));
    }

    /** @param  list<string>  $paths */
    private function writeIndex(array $paths): void
    {
        Directory::ensure($this->home(), 0700);
        $mask = umask(0077);
        try {
            File::put(
                $this->globalIndexPath(),
                json_encode(array_values($paths), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
            );
        } finally {
            umask($mask);
        }
    }

    /** @return array{project_id: string, workspace_id: string, repository_id: string, checkout_id: string}|null */
    private function readIdentity(string $file): ?array
    {
        if (is_link($file) || is_link(dirname($file))) {
            throw new RuntimeException('WORKSPACE_IDENTITY_INVALID: '.$file.' must not be a symbolic link.');
        }
        if (! is_file($file)) {
            return null;
        }

        try {
            $data = json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('WORKSPACE_IDENTITY_INVALID: '.$file.' is not valid JSON.');
        }

        $identity = [];
        foreach (['project_id', 'workspace_id', 'repository_id', 'checkout_id'] as $key) {
            if (! is_array($data) || ! is_string($data[$key] ?? null) || ! Str::isUuid($data[$key])) {
                throw new RuntimeException('WORKSPACE_IDENTITY_INVALID: '.$file.' needs a UUID '.$key.'. Molly does not replace a damaged identity file.');
            }
            $identity[$key] = strtolower($data[$key]);
        }

        return $identity;
    }

    /** @return array{project_id: string, workspace_id: string, repository_id: string, checkout_id: string} */
    private function createIdentity(string $file): array
    {
        $identity = [
            'project_id' => $this->makeId(),
            'workspace_id' => $this->makeId(),
            'repository_id' => $this->makeId(),
            'checkout_id' => $this->makeId(),
        ];

        Directory::molly(dirname($file, 2), basename($file));
        Directory::ensure(dirname($file), 0700);
        $staged = dirname($file).'/.identity-'.bin2hex(random_bytes(8)).'.tmp';
        File::put($staged, json_encode(['schema' => 'molly.checkout-identity.v1', ...$identity], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");

        try {
            // link() fails when another process already wrote the file; that file wins.
            if (! @link($staged, $file)) {
                return $this->readIdentity($file)
                    ?? throw new RuntimeException('WORKSPACE_IDENTITY_INVALID: Molly could not write '.$file.'.');
            }
        } finally {
            @unlink($staged);
        }

        return $identity;
    }

    private function normalizePath(string $path): string
    {
        $real = realpath($path);

        return $real === false ? rtrim(str_replace('\\', '/', $path), '/') : str_replace('\\', '/', $real);
    }
}
