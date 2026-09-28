<?php

namespace Sifrious\Molly\Workspace;

use RuntimeException;

/** Read-only Git observation for a checkout path. It never creates a repository or invents identity from the path alone. */
final class ObserveCheckout
{
    /**
     * The path stays the workspace (the Laravel app, where .molly/ lives); repository_root is
     * the top level of the Git work tree that tracks it, which may be a parent directory.
     *
     * @return array{path: string, repository_root: string, branch: ?string, head: ?string, remote_url: ?string, remote_identity: ?string}
     */
    public function handle(string $path): array
    {
        $real = realpath($path);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('WORKSPACE_INVALID: Workspace path must be an existing directory.');
        }
        GitBinary::require();

        $root = self::requireCheckout($real);

        // A repository with no commit yet has no HEAD; the binder reports WORKSPACE_REVISION_MISSING.
        $head = $this->git($real, ['rev-parse', '--verify', '--quiet', 'HEAD'], allowFail: true);
        $branch = $this->git($real, ['rev-parse', '--abbrev-ref', 'HEAD'], allowFail: true);
        if ($branch === 'HEAD') {
            $branch = null;
        }
        $remote = $this->git($real, ['config', '--get', 'remote.origin.url'], allowFail: true);
        $remoteIdentity = $this->remoteIdentity($remote);

        return [
            'path' => is_dir($path) ? $path : $real,
            'repository_root' => $root,
            'branch' => $branch !== '' ? $branch : null,
            'head' => $head !== '' ? strtolower($head) : null,
            'remote_url' => $remote !== '' ? $remote : null,
            'remote_identity' => $remoteIdentity,
        ];
    }

    /**
     * Where the path sits in Git: the top level of the work tree that tracks it, and the
     * repository root that ignores it, if any. The path may be the top level itself or a
     * subdirectory, such as a Laravel app in backend/ of a monorepo. A .git file (a linked
     * worktree or a submodule) counts the same as a .git directory.
     *
     * @return array{root: ?string, ignored_by: ?string}
     */
    public static function locate(string $path): array
    {
        $path = rtrim($path, '/');
        if ($path === '' || ! is_dir($path)) {
            return ['root' => null, 'ignored_by' => null];
        }
        if (GitBinary::find() === null) {
            return ['root' => file_exists($path.'/.git') ? $path : null, 'ignored_by' => null];
        }
        [$code, $inside] = self::run($path, ['rev-parse', '--is-inside-work-tree']);
        if ($code !== 0 || $inside !== 'true') {
            return ['root' => null, 'ignored_by' => null];
        }
        [$code, $root] = self::run($path, ['rev-parse', '--show-toplevel']);
        if ($code !== 0 || $root === '') {
            return ['root' => null, 'ignored_by' => null];
        }
        // An ignored directory, such as vendor/, is not in the repository's commits.
        [$ignored] = self::run($path, ['check-ignore', '--quiet', '.']);
        if ($ignored === 0) {
            return ['root' => null, 'ignored_by' => $root];
        }

        return ['root' => $root, 'ignored_by' => null];
    }

    /** The path relative to the top level of its work tree, such as backend or scratch/app, or . at the top level. */
    public static function prefix(string $path): string
    {
        [$code, $prefix] = self::run($path, ['rev-parse', '--show-prefix']);
        $prefix = rtrim($prefix, '/');

        return $code === 0 && $prefix !== '' ? $prefix : '.';
    }

    /** Whether the HEAD commit contains at least one file under the path. */
    public function headContainsFiles(string $path): bool
    {
        if (! is_dir($path) || GitBinary::find() === null) {
            return false;
        }
        [$code, $out] = self::run($path, ['ls-tree', '--name-only', 'HEAD', '--', '.']);

        return $code === 0 && $out !== '';
    }

    /**
     * The commands that commit a path into the repository that already holds it. Molly prints
     * them for the user to run and never runs them itself.
     *
     * @return list<string>
     */
    public static function commitCommands(string $root, string $path): array
    {
        $prefix = self::prefix($path);

        return [
            'git -C '.escapeshellarg($root).' add '.escapeshellarg($prefix),
            'git -C '.escapeshellarg($root).' commit -m '.escapeshellarg($prefix === '.' ? 'Start' : 'Add '.$prefix),
        ];
    }

    /** Whether a Git work tree tracks the path, at its top level or in a subdirectory. */
    public static function isCheckout(string $path): bool
    {
        return self::locate($path)['root'] !== null;
    }

    /**
     * Refuse a path that no Git work tree tracks and return the repository root.
     * Molly never creates a repository or a commit in the user's project.
     */
    public static function requireCheckout(string $path): string
    {
        $location = self::locate($path);
        if ($location['ignored_by'] !== null) {
            throw new RuntimeException('WORKSPACE_NOT_GIT: '.$path.' is ignored by the Git repository at '.$location['ignored_by'].', so its files are not in any commit. Stop ignoring it and commit it, then try again.');
        }
        if ($location['root'] === null) {
            throw new RuntimeException('WORKSPACE_NOT_GIT: '.$path.' is not a Git repository. Run git init and commit your work, then try again.');
        }

        return $location['root'];
    }

    /** Refuse a path that is not a Git checkout or has no commit yet, before anything is written. */
    public function requireCommit(string $path): string
    {
        self::requireCheckout($path);
        $head = $this->head($path);
        if ($head === null) {
            throw new RuntimeException('WORKSPACE_REVISION_MISSING: '.$path.' has no commit yet. Commit your work, then try again.');
        }

        return $head;
    }

    /**
     * The checkout's HEAD commit, or null when the path is not a Git checkout.
     * Unlike handle(), this never throws for a missing repository.
     */
    public function head(string $path): ?string
    {
        if (! is_dir($path) || GitBinary::find() === null) {
            return null;
        }
        $head = strtolower($this->git($path, ['rev-parse', '--verify', '--quiet', 'HEAD'], allowFail: true));

        return preg_match('/\A[0-9a-f]{40}\z/', $head) === 1 ? $head : null;
    }

    /** @param  list<string>  $args */
    private function git(string $cwd, array $args, bool $allowFail = false): string
    {
        [$code, $out, $err] = self::run($cwd, $args);
        if ($code !== 0) {
            if ($allowFail) {
                return '';
            }
            throw new RuntimeException('WORKSPACE_GIT_FAILED: '.($err !== '' ? $err : 'git exited '.$code));
        }

        return $out;
    }

    /**
     * @param  list<string>  $args
     * @return array{0: int, 1: string, 2: string}
     */
    private static function run(string $cwd, array $args): array
    {
        $cmd = array_merge(['git', '-C', $cwd], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (! is_resource($proc)) {
            throw new RuntimeException('WORKSPACE_GIT_FAILED: Could not start git.');
        }
        $out = trim(stream_get_contents($pipes[1]) ?: '');
        $err = trim(stream_get_contents($pipes[2]) ?: '');
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out, $err];
    }

    private function remoteIdentity(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (preg_match('#github\.com[:/]([^/]+)/([^/.]+)(?:\.git)?$#', $url, $m)) {
            return 'github:'.$m[1].'/'.$m[2];
        }

        return 'git:'.$url;
    }
}
