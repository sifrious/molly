<?php

namespace Sifrious\Molly\Actions;

use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class EvaluateChanges
{
    public function __construct(private ReviewChanges $review) {}

    /**
     * @param  array<string, string|null>  $before
     * @param  array<string, string>  $after
     * @return array{verification: array, review: array, branches: list<array>, mode: string}
     */
    public function handle(string $prompt, string $workspace, array $before, array $after, string $testPath, string $evidenceDirectory, ?Closure $shouldStop = null, ?string $workspaceLease = null, ?Closure $recordBranches = null): array
    {
        $attempt = bin2hex(random_bytes(16));
        $branches = $processes = $inputs = $results = [];
        foreach (['verification', 'review'] as $kind) {
            $id = $kind.'-'.bin2hex(random_bytes(12));
            $branches[$kind] = ['kind' => $kind, 'branch_id' => $id, 'attempt_id' => $attempt, 'execution_target' => 'local', 'provider' => $kind === 'review' ? 'ollama' : null, 'model' => $kind === 'review' ? config('molly.model') : null, 'started_at' => now()->toISOString(), 'finished_at' => null, 'status' => 'running', 'result_ref' => $evidenceDirectory.'/'.$id.'.json', 'failure_classification' => null];
            $inputs[$kind] = $evidenceDirectory.'/'.$id.'-input.json';
            try {
                $this->writeInput($inputs[$kind], [...$branches[$kind], 'prompt' => $prompt, 'workspace' => $workspace, 'workspace_lease' => $workspaceLease, 'before' => $before, 'after' => $after, 'test_path' => $testPath, 'evidence_directory' => $evidenceDirectory, 'config' => $this->settings()]);
                $processes[$kind] = Process::path(base_path())->forever()->start([PHP_BINARY, base_path('artisan'), 'molly:check', $inputs[$kind], $branches[$kind]['result_ref'], '--no-interaction']);
                $branches[$kind]['group_marker'] = $branches[$kind]['result_ref'].'.group';
                $branches[$kind]['pid'] = $processes[$kind]->id();
                $branches[$kind]['deadline'] = microtime(true) + $this->timeout($kind);
            } catch (Throwable $exception) {
                $this->fail($branches[$kind], $results[$kind], 'branch_start_failed', $exception->getMessage());
            }
        }
        try {
            $recordBranches?->__invoke($this->metadata($branches));
            $this->join($processes, $branches, $results, $shouldStop, $recordBranches);
        } finally {
            foreach ($processes as $kind => $process) {
                $this->stop($process, $branches[$kind]);
            }
            foreach ($inputs as $input) {
                @unlink($input);
            }
        }

        return ['verification' => $results['verification'], 'review' => $results['review'], 'branches' => $this->metadata($branches), 'mode' => 'parallel'];
    }

    /** @param array<string, array> $branches
     * @return list<array>
     */
    private function metadata(array $branches): array
    {
        return array_values(array_map(function (array $branch): array {
            unset($branch['deadline'], $branch['pid'], $branch['group_marker']);

            return $branch;
        }, $branches));
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return ['molly.model' => config('molly.model'), 'molly.timeout' => config('molly.timeout'), 'molly.test_timeout' => config('molly.test_timeout'), 'ai.providers.ollama' => config('ai.providers.ollama')];
    }

    private function timeout(string $kind): int
    {
        return max(1, min(3600, (int) config($kind === 'review' ? 'molly.timeout' : 'molly.test_timeout', 120))) + 10;
    }

    /** @param array<string, mixed> $input */
    private function writeInput(string $path, array $input): void
    {
        if (! is_dir(dirname($path)) && ! @mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('Cannot create check evidence directory.');
        }
        $mask = umask(0077);
        try {
            if (file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new RuntimeException('Cannot write check input.');
            }
        } finally {
            umask($mask);
        }
    }

    /** @param array<string, InvokedProcess> $processes
     * @param  array<string, array>  $branches
     * @param  array<string, array>  $results
     */
    private function join(array $processes, array &$branches, array &$results, ?Closure $shouldStop, ?Closure $recordBranches): void
    {
        while (count($results) < 2) {
            try {
                $cancelled = (bool) $shouldStop?->__invoke();
            } catch (Throwable) {
                $cancelled = true;
            }
            foreach ($processes as $kind => $process) {
                if (isset($results[$kind])) {
                    continue;
                }
                if ($cancelled || microtime(true) >= $branches[$kind]['deadline']) {
                    $this->stop($process, $branches[$kind]);
                    $this->fail($branches[$kind], $results[$kind], $cancelled ? 'branch_cancelled' : 'branch_timeout', $cancelled ? 'The run received a stop request.' : 'The check exceeded its process timeout.');
                } elseif (! $process->running()) {
                    $this->collect($process, $branches[$kind], $results[$kind]);
                    $this->stop($process, $branches[$kind]);
                }
                if (isset($results[$kind])) {
                    $recordBranches?->__invoke($this->metadata($branches));
                }
            }
            if (count($results) < 2) {
                usleep(20000);
            }
        }
    }

    /** @param array<string, mixed> $branch
     * @param  array<string, mixed>|null  $result
     */
    private function collect(InvokedProcess $process, array &$branch, ?array &$result): void
    {
        try {
            $exit = $process->wait();
            $path = $branch['result_ref'];
            $envelope = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : null;
            if (! is_array($envelope) || ($envelope['branch_id'] ?? null) !== $branch['branch_id'] || ($envelope['attempt_id'] ?? null) !== $branch['attempt_id'] || ! is_array($envelope['result'] ?? null) || ! in_array($envelope['status'] ?? null, ['passed', 'failed'], true)) {
                throw new RuntimeException('The check did not return matching, complete evidence. '.trim($exit->output().$exit->errorOutput()));
            }
            if ($envelope['status'] === 'passed' && (! $exit->successful() || ! $this->passed($branch['kind'], $envelope['result']))) {
                throw new RuntimeException('The check exited unsuccessfully.');
            }
            $result = $envelope['result'];
            $branch['status'] = ($envelope['failure_classification'] ?? null) === 'test_timeout' ? 'timed_out' : $envelope['status'];
            $branch['failure_classification'] = $envelope['failure_classification'] ?? null;
            $branch['finished_at'] = now()->toISOString();
        } catch (Throwable $exception) {
            $this->fail($branch, $result, 'branch_result_invalid', $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $result */
    private function passed(string $kind, array $result): bool
    {
        if ($kind === 'verification') {
            return ($result['status'] ?? null) === 'passed' && ($result['tests'] ?? 0) > 0
                && ($result['failures'] ?? -1) === 0 && ($result['errors'] ?? -1) === 0 && ($result['skipped'] ?? -1) === 0;
        }

        return $this->review->passed($result);
    }

    /** @param array<string, mixed> $branch
     * @param  array<string, mixed>|null  $result
     */
    private function fail(array &$branch, ?array &$result, string $reason, string $message): void
    {
        $branch['status'] = match ($reason) {
            'branch_cancelled' => 'cancelled',
            'branch_timeout' => 'timed_out',
            default => 'failed',
        };
        $branch['finished_at'] = now()->toISOString();
        $branch['failure_classification'] = $reason;
        $result = $branch['kind'] === 'verification'
            ? ['status' => 'failed', 'tests' => 0, 'assertions' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'output' => $message, 'reason' => $reason]
            : ['checks' => [], 'findings' => [], 'reason' => $reason, 'error' => $message];
        $temporary = $branch['result_ref'].'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            $this->writeInput($temporary, ['branch_id' => $branch['branch_id'], 'attempt_id' => $branch['attempt_id'], 'status' => $branch['status'], 'failure_classification' => $reason, 'result' => $result]);
            if (! @rename($temporary, $branch['result_ref'])) {
                $branch['result_ref'] = null;
            }
        } catch (Throwable) {
            $branch['result_ref'] = null;
        } finally {
            @unlink($temporary);
        }
    }

    /** @param array<string, mixed> $branch */
    private function stop(InvokedProcess $process, array $branch): void
    {
        $pid = $branch['pid'] ?? null;
        $marker = $branch['group_marker'];
        $ownsGroup = is_int($pid) && function_exists('posix_kill')
            && is_file($marker) && trim(file_get_contents($marker)) === (string) $pid;
        if ($ownsGroup) {
            @posix_kill(-$pid, 15);
        }
        $process->stop(0.2);
        if (is_int($pid) && function_exists('posix_kill')
            && is_file($marker) && trim(file_get_contents($marker)) === (string) $pid) {
            @posix_kill(-$pid, 9);
        }
        @unlink($marker);
    }
}
