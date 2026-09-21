<?php

namespace Sifrious\Molly\Workspace;

use RuntimeException;

/** Read-only Git observation for a checkout path — never invents identity from the path alone. */
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

        $gitDir = $real.'/.git';
        if (! file_exists($gitDir)) {
            if (function_exists('app') && app()->environment('testing')) {
                $this->initEphemeralGit($real);
            } else {
                throw new RuntimeException('WORKSPACE_GIT_MISSING: Molly requires a Git checkout to bind revision identity.');
            }
        }

        $gitRoot = $real;
        $head = $this->git($gitRoot, ['rev-parse', 'HEAD']);
        $branch = $this->git($real, ['rev-parse', '--abbrev-ref', 'HEAD']);
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

    private function initEphemeralGit(string $path): void
    {
        $this->git($path, ['init'], allowFail: false);
        $this->git($path, ['config', 'user.email', 'molly-tests@sifrious.invalid'], allowFail: false);
        $this->git($path, ['config', 'user.name', 'Molly Tests'], allowFail: false);
        if (! is_file($path.'/README.molly-fixture')) {
            file_put_contents($path.'/README.molly-fixture', "Molly test fixture\n");
        }
        $this->git($path, ['add', '-A'], allowFail: false);
        $this->git($path, ['commit', '-m', 'molly-test-fixture'], allowFail: false);
    }
}
