<?php

namespace Sifrious\Molly\Projects;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shared Molly project index for CLI and Bloom.
 *
 * Per-project: `<path>/.molly/project.json`
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

        $directory = dirname($this->projectFile($normalized->path));
        File::ensureDirectoryExists($directory, 0700);
        $mask = umask(0077);
        try {
            File::put(
                $this->projectFile($normalized->path),
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
        File::ensureDirectoryExists($this->home(), 0700);
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

    private function normalizePath(string $path): string
    {
        $real = realpath($path);

        return $real === false ? rtrim(str_replace('\\', '/', $path), '/') : str_replace('\\', '/', $real);
    }
}
