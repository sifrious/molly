<?php

namespace Sifrious\Molly\Workspace;

use RuntimeException;

/** Read-only Git observation for a checkout path. It never creates a repository or invents identity from the path alone. */
final class ObserveCheckout
{
    /**
     * @return array{path: string, branch: ?string, head: ?string, remote_url: ?string, remote_identity: ?string}
     */
    public function handle(string $path): array
    {
        $real = realpath($path);
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('WORKSPACE_INVALID: Workspace path must be an existing directory.');
        }
        GitBinary::require();

        self::requireCheckout($real);

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
            'branch' => $branch !== '' ? $branch : null,
            'head' => $head !== '' ? strtolower($head) : null,
            'remote_url' => $remote !== '' ? $remote : null,
            'remote_identity' => $remoteIdentity,
        ];
    }

    /** Whether the path is the root of a Git checkout (a clone's .git directory or a worktree's .git file). */
    public static function isCheckout(string $path): bool
    {
        return file_exists(rtrim($path, '/').'/.git');
    }

    /** Refuse a path that is not a Git checkout. Molly never creates a repository or a commit in the user's project. */
    public static function requireCheckout(string $path): void
    {
        if (! self::isCheckout($path)) {
            throw new RuntimeException('WORKSPACE_NOT_GIT: '.$path.' is not a Git repository. Run git init and commit your work, then try again.');
        }
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
        $cmd = array_merge(['git', '-C', $cwd], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (! is_resource($proc)) {
            throw new RuntimeException('WORKSPACE_GIT_FAILED: Could not start git.');
        }
        $out = trim(stream_get_contents($pipes[1]) ?: '');
        $err = trim(stream_get_contents($pipes[2]) ?: '');
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            if ($allowFail) {
                return '';
            }
            throw new RuntimeException('WORKSPACE_GIT_FAILED: '.($err !== '' ? $err : 'git exited '.$code));
        }

        return $out;
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
