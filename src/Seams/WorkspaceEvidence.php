<?php

namespace Sifrious\Molly\Seams;

use Sifrious\Molly\Workspace;
use Sifrious\Molly\Workspace\GitBinary;
use Sifrious\Molly\Workspace\ObserveCheckout;
use Symfony\Component\Process\Process;

final class WorkspaceEvidence
{
    public function __construct(private ObserveCheckout $checkout, private PackFiles $files) {}

    public function capture(string $workspace, array $exclude = []): array
    {
        $workspace = (new Workspace($workspace))->path;
        GitBinary::require();
        $head = $this->checkout->head($workspace);
        if ($head === null) {
            throw new SeamError('ENVIRONMENT_UNAVAILABLE', 'Commit the application before creating a seam plan.', $workspace);
        }
        $process = new Process(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $workspace, timeout: 30);
        $process->mustRun();
        $paths = array_unique(array_filter(explode("\0", $process->getOutput())));
        foreach (['.env', '.env.testing'] as $environment) {
            if (file_exists($workspace.'/'.$environment)) {
                $paths[] = $environment;
            }
        }
        $hashes = [];
        foreach ($paths as $path) {
            if (str_starts_with($path, '.molly/') || in_array($path, $exclude, true)) {
                continue;
            }
            $absolute = $workspace.'/'.$path;
            if (str_contains($path, '..') || str_starts_with($path, '/') || is_link($absolute)) {
                throw new SeamError('PATH_OUTSIDE_WORKSPACE', 'Baseline files must be regular files within the application.', $path);
            }
            $parent = $workspace;
            foreach (explode('/', $path) as $segment) {
                $parent .= '/'.$segment;
                if (is_link($parent)) {
                    throw new SeamError('PATH_OUTSIDE_WORKSPACE', 'Baseline files cannot pass through linked directories.', $path);
                }
            }
            $hashes[$path] = is_file($absolute) ? hash_file('sha256', $absolute) : null;
        }
        ksort($hashes, SORT_STRING);

        $environment = ['php' => PHP_VERSION, 'binary' => hash_file('sha256', PHP_BINARY),
            'extensions' => get_loaded_extensions(), 'installed_packages' => is_file($workspace.'/vendor/composer/installed.json') ? hash_file('sha256', $workspace.'/vendor/composer/installed.json') : null];
        sort($environment['extensions']);

        return ['commit' => $head, 'tree_digest' => hash('sha256', $this->files->canonical($hashes)), 'files' => $hashes,
            'environment_digest' => hash('sha256', $this->files->canonical($environment))];
    }

    public function assertScope(array $baseline, array $candidate, array $allowed): void
    {
        if ($baseline['environment_digest'] !== $candidate['environment_digest']) {
            throw new SeamError('STALE_EVIDENCE', 'PHP or installed dependencies changed. Create a new plan revision.', 'environment');
        }
        foreach (array_unique([...array_keys($baseline['files']), ...array_keys($candidate['files'])]) as $path) {
            if (($baseline['files'][$path] ?? null) !== ($candidate['files'][$path] ?? null) && ! in_array($path, $allowed, true)) {
                throw new SeamError('CAPABILITY_DENIED', 'A file outside the approved production scope changed.', $path,
                    $baseline['files'][$path] ?? null, $candidate['files'][$path] ?? null);
            }
        }
    }
}
