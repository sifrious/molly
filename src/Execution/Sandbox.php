<?php

namespace Sifrious\Molly\Execution;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class Sandbox
{
    /** @var list<string> */
    public const HOST_READ_PATHS = ['/usr', '/bin', '/lib', '/lib64', '/etc', '/dev', '/proc'];

    /** @var list<string> */
    public const ENV_ALLOWLIST = ['PATH', 'HOME', 'USER', 'LOGNAME', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TZ', 'TERM', 'PWD', 'TMPDIR'];

    private ?bool $available = null;

    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        if (Landlock::abi() === null
            || ! function_exists('pcntl_unshare')
            || ! function_exists('proc_open')
            || ! defined('CLONE_NEWUSER')
            || ! defined('CLONE_NEWNET')) {
            return $this->available = false;
        }

        try {
            $process = proc_open(
                [PHP_BINARY, '-r', 'exit(@pcntl_unshare(CLONE_NEWUSER | CLONE_NEWNET) ? 0 : 1);'],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', '/dev/null', 'w'],
                    2 => ['file', '/dev/null', 'w'],
                ],
                $pipes,
            );
        } catch (\Throwable) {
            return $this->available = false;
        }

        if (! is_resource($process)) {
            return $this->available = false;
        }

        return $this->available = proc_close($process) === 0;
    }

    public function allowUnsafe(): bool
    {
        return (bool) config('molly.sandbox.allow_unsafe', false);
    }

    public function refuseSafeWorkflow(): void
    {
        if ($this->available()) {
            return;
        }
        if ($this->allowUnsafe()) {
            return;
        }

        throw new RuntimeException('SANDBOX_UNAVAILABLE: Molly cannot isolate the writer and Pest verifier on this host. Do not run the safe workflow here.');
    }

    /**
     * @param  list<string>  $writablePaths
     * @param  list<string>  $command
     * @param  array<string, string|false>  $env
     * @return array{exit_code: int, output: string, error: string, timed_out: bool}
     */
    public function run(string $workspace, array $writablePaths, array $command, string $evidenceDirectory, int $timeout, array $env = [], bool $network = false): array
    {
        $this->refuseSafeWorkflow();
        if (! $this->available()) {
            throw new RuntimeException('SANDBOX_UNAVAILABLE: Molly cannot isolate the writer and Pest verifier on this host.');
        }

        $tmp = $evidenceDirectory.'/sandbox-tmp';
        if (! is_dir($tmp) && ! mkdir($tmp, 0700, true) && ! is_dir($tmp)) {
            throw new RuntimeException('SANDBOX_UNAVAILABLE: Molly could not create the sandbox temp directory.');
        }

        $policy = [
            'workspace' => $workspace,
            'writable' => array_values($writablePaths),
            'read' => $this->readPaths($workspace, $command),
            'evidence' => $evidenceDirectory,
            'tmp' => $tmp,
            'network' => $network,
            'command' => $command,
            'env' => $env,
        ];
        $policyPath = $evidenceDirectory.'/sandbox-policy-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($policyPath, json_encode($policy, JSON_THROW_ON_ERROR));

        try {
            $result = Process::timeout(max(1, min(3600, $timeout)))
                ->env($this->childEnvironment($env, $tmp, $workspace))
                ->run([PHP_BINARY, '-d', 'ffi.enable=1', __DIR__.'/run-sandboxed.php', $policyPath]);

            return [
                'exit_code' => $result->exitCode() ?? 1,
                'output' => $result->output(),
                'error' => $result->errorOutput(),
                'timed_out' => false,
            ];
        } catch (ProcessTimedOutException $exception) {
            return [
                'exit_code' => 124,
                'output' => $exception->result->output(),
                'error' => $exception->result->errorOutput(),
                'timed_out' => true,
            ];
        } finally {
            @unlink($policyPath);
        }
    }

    /**
     * @param  array<string, string|false>  $env
     * @return array<string, string|false>
     */
    public function childEnvironment(array $env, string $tmp, string $workspace): array
    {
        $child = [];
        foreach (self::ENV_ALLOWLIST as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $child[$key] = $value;
            }
        }
        foreach ($env as $key => $value) {
            $child[$key] = $value;
        }
        $child['TMPDIR'] = $tmp;
        $child['PWD'] = $workspace;
        foreach (array_keys($_ENV + $_SERVER) as $key) {
            if (! is_string($key) || array_key_exists($key, $child) || in_array($key, self::ENV_ALLOWLIST, true)) {
                continue;
            }
            if (preg_match('/(KEY|TOKEN|SECRET|PASSWORD|CREDENTIAL|AWS_|SSH_)/i', $key) === 1) {
                $child[$key] = false;
            }
        }

        return $child;
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function readPaths(string $workspace, array $command): array
    {
        $paths = [$workspace, __DIR__, dirname(PHP_BINARY)];
        foreach ([$workspace.'/vendor', $command[0] ?? null, $command[1] ?? null] as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                $paths[] = $path;
            } elseif (is_string($path) && $path !== '' && is_dir($path)) {
                $paths[] = $path;
            }
        }

        // Pest resolves its project root from the real autoloader path
        // (dirname(vendor/autoload.php, 2)), so the project that really owns
        // the vendor directory must be readable. For a normal workspace that
        // is the workspace itself; it only differs when vendor is a symlink.
        $vendor = realpath($workspace.'/vendor');
        if ($vendor !== false) {
            $paths[] = dirname($vendor);
        }

        $resolved = [];
        foreach ($paths as $path) {
            $real = realpath($path);
            if ($real !== false) {
                $resolved[] = $real;
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @param  list<array{path: string, content: string}>  $edits
     * @param  list<string>  $writablePaths
     */
    public function apply(string $workspace, array $edits, array $writablePaths, string $evidenceDirectory): void
    {
        $this->refuseSafeWorkflow();
        if (! $this->available()) {
            throw new RuntimeException('SANDBOX_UNAVAILABLE: Molly cannot isolate the writer and Pest verifier on this host.');
        }

        $editsPath = $evidenceDirectory.'/proposal-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($editsPath, json_encode($edits, JSON_THROW_ON_ERROR));
        foreach ($edits as $edit) {
            $absolute = $workspace.'/'.$edit['path'];
            if (! is_dir(dirname($absolute)) && ! mkdir(dirname($absolute), 0777, true) && ! is_dir(dirname($absolute))) {
                throw new RuntimeException('WORKSPACE_WRITE_FAILED: Molly could not apply the proposal.');
            }
            if (! file_exists($absolute) && file_put_contents($absolute, '') === false) {
                throw new RuntimeException('WORKSPACE_WRITE_FAILED: Molly could not apply the proposal.');
            }
        }

        try {
            $result = $this->run(
                $workspace,
                $writablePaths,
                [PHP_BINARY, __DIR__.'/apply-files.php', $editsPath, $workspace],
                $evidenceDirectory,
                30,
            );
            if ($result['timed_out'] || $result['exit_code'] !== 0) {
                throw new RuntimeException('WORKSPACE_WRITE_FAILED: Molly could not apply the proposal inside the sandbox.');
            }
        } finally {
            @unlink($editsPath);
        }
    }
}
