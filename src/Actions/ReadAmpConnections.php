<?php

namespace Sifrious\Molly\Actions;

use DateTimeImmutable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use JsonException;
use RuntimeException;
use Throwable;

class ReadAmpConnections
{
    public function __construct(private string $binary = 'amp') {}

    /**
     * @param  list<string>  $threadIds
     * @return array{status: string, reason: ?string, provider_version: ?string, observed_at: string, threads: list<array{thread_id: string, status: string, reason: ?string, executor_connected: ?bool, working: ?bool, executor_type: ?string}>}
     */
    public function handle(array $threadIds): array
    {
        $ids = [];
        foreach ($threadIds as $id) {
            if (! is_string($id) || ! preg_match('/\AT-[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/iD', trim($id))) {
                throw new RuntimeException('AMP_THREAD_INVALID: Use an Amp thread ID beginning with T- followed by a UUID.');
            }
            $ids[] = 'T-'.strtolower(substr(trim($id), 2));
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > 20) {
            throw new RuntimeException('AMP_THREAD_LIMIT: Read at most 20 Amp threads at a time.');
        }

        $report = [
            'status' => 'unknown', 'reason' => null, 'provider_version' => null,
            'observed_at' => now()->toIso8601String(),
            'threads' => array_map(fn (string $id): array => [
                'thread_id' => $id, 'status' => 'unknown',
                'reason' => 'AMP_THREAD_NOT_OBSERVED: Amp did not report a current connection for this thread.',
                'executor_connected' => null, 'working' => null, 'executor_type' => null,
            ], $ids),
        ];
        if ($ids === []) {
            return [...$report, 'reason' => 'AMP_NO_THREADS: No Amp threads were requested.'];
        }

        $deadline = microtime(true) + 10;
        $bytes = 0;
        $version = $this->capture(['--version'], 2, 4096, $deadline, $bytes);
        if ($version['status'] !== 'ok') {
            return $this->unavailable($report, $version['reason']);
        }
        if (! preg_match('/\A(\d+\.\d+\.\d+(?:-[a-zA-Z0-9.]+)?)(?:\s|\z)/D', trim($version['output']), $match)) {
            return $this->unavailable($report, 'AMP_VERSION_INVALID: Amp did not return a recognizable version.');
        }
        $report['provider_version'] = $match[1];

        $stream = $this->capture(['top', '--stream-jsonl'], 3, 1048576, $deadline, $bytes);
        if (! in_array($stream['status'], ['ok', 'timeout'], true)) {
            return $this->unavailable($report, $stream['reason']);
        }
        try {
            $snapshot = $this->snapshot($stream['output'], $ids);
        } catch (RuntimeException $exception) {
            return $this->unavailable($report, $exception->getMessage());
        }

        if ($snapshot === null) {
            $report['reason'] = $stream['status'] === 'timeout'
                ? 'AMP_TIMEOUT: Amp did not return a complete connection snapshot before the read timed out.'
                : 'AMP_NO_SNAPSHOT: Amp did not return a complete connection snapshot.';
        } elseif ($snapshot['reconnecting']) {
            $report['reason'] = 'AMP_RECONNECTING: Amp is reconnecting. Current executor state is unknown.';
        } else {
            foreach ($report['threads'] as &$thread) {
                if (isset($snapshot['threads'][$thread['thread_id']])) {
                    $thread = [...$thread, ...$snapshot['threads'][$thread['thread_id']], 'status' => 'observed', 'reason' => null];
                }
            }
            unset($thread);
        }

        foreach ($report['threads'] as &$thread) {
            if ($thread['status'] === 'unknown' && $report['reason'] !== null) {
                $thread['reason'] = $report['reason'];
            }
            $metadata = $this->capture(['threads', 'export', $thread['thread_id']], 2, 5242880, $deadline, $bytes);
            try {
                $export = $metadata['status'] === 'ok' ? json_decode($metadata['output'], true, 64, JSON_THROW_ON_ERROR) : null;
            } catch (JsonException) {
                $export = null;
            }
            $type = is_array($export) && ($export['id'] ?? null) === $thread['thread_id'] ? ($export['meta']['executorType'] ?? null) : null;
            if (is_string($type) && preg_match('/\A[a-zA-Z0-9_.:-]{1,64}\z/D', $type)) {
                $thread['executor_type'] = $type;
            } elseif ($thread['reason'] === null) {
                $thread['reason'] = 'AMP_METADATA_UNAVAILABLE: Amp did not return an executor type for this thread.';
            }
        }
        unset($thread);

        $observed = count(array_filter($report['threads'], fn (array $thread): bool => $thread['status'] === 'observed'));
        if ($observed > 0) {
            $report['status'] = 'observed';
        }
        $report['reason'] ??= $observed === count($ids) ? null : 'AMP_PARTIAL: Some requested threads have no current connection observation.';

        return $report;
    }

    /**
     * @param  list<string>  $ids
     * @return ?array{reconnecting: bool, threads: array<string, array{executor_connected: bool, working: bool}>}
     */
    private function snapshot(string $output, array $ids): ?array
    {
        $latest = null;
        $lines = explode("\n", $output);
        if (trim(array_pop($lines)) !== '') {
            throw new RuntimeException('AMP_RESPONSE_INVALID: Amp returned an incomplete connection snapshot.');
        }
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            try {
                $data = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('AMP_RESPONSE_INVALID: Amp returned an invalid connection snapshot.');
            }
            if (! is_array($data) || ! is_bool($data['reconnecting'] ?? null)
                || ! is_array($data['threads'] ?? null) || ! array_is_list($data['threads'])
                || ! is_string($data['updatedAt'] ?? null)
                || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D', $data['updatedAt'])) {
                throw new RuntimeException('AMP_RESPONSE_INVALID: Amp returned an invalid connection snapshot.');
            }
            try {
                $age = abs(now()->getTimestamp() - (new DateTimeImmutable($data['updatedAt']))->getTimestamp());
            } catch (Throwable) {
                $age = PHP_INT_MAX;
            }
            if ($age > 30) {
                throw new RuntimeException('AMP_SNAPSHOT_STALE: Amp returned an old connection snapshot. Current executor state is unknown.');
            }
            $latest = ['reconnecting' => $data['reconnecting'], 'threads' => []];
            foreach ($data['threads'] as $thread) {
                if (! is_array($thread) || ! in_array($thread['id'] ?? null, $ids, true)) {
                    continue;
                }
                if (! is_bool($thread['executorConnected'] ?? null) || ! is_bool($thread['working'] ?? null)
                    || isset($latest['threads'][$thread['id']])) {
                    throw new RuntimeException('AMP_RESPONSE_INVALID: Amp returned invalid connection fields for a requested thread.');
                }
                $latest['threads'][$thread['id']] = [
                    'executor_connected' => $thread['executorConnected'], 'working' => $thread['working'],
                ];
            }
        }

        return $latest;
    }

    /**
     * @param  list<string>  $arguments
     * @return array{status: string, reason: ?string, output: string}
     */
    private function capture(array $arguments, int $timeout, int $limit, float $deadline, int &$totalBytes): array
    {
        if ($totalBytes >= 8388608) {
            return ['status' => 'unavailable', 'reason' => 'AMP_OUTPUT_LIMIT: Amp returned more data than Molly can inspect in one read.', 'output' => ''];
        }
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            return ['status' => 'timeout', 'reason' => 'AMP_TIMEOUT: The Amp read reached its time limit.', 'output' => ''];
        }
        $output = '';
        $errors = '';
        $bytes = 0;
        $tooLarge = false;
        $process = null;

        try {
            $process = Process::timeout(min($timeout, $remaining))->start([$this->binary, ...$arguments], function (string $type, string $chunk) use (&$output, &$errors, &$bytes, &$totalBytes, &$tooLarge, $limit): void {
                $bytes += strlen($chunk);
                $totalBytes += strlen($chunk);
                if ($bytes > $limit || $totalBytes > 8388608) {
                    $tooLarge = true;

                    return;
                }
                if ($type === 'out') {
                    $output .= $chunk;
                } else {
                    $errors .= $chunk;
                }
            });
            while ($process->running()) {
                if ($tooLarge) {
                    break;
                }
                $process->ensureNotTimedOut();
                Sleep::usleep(10000);
            }
            if ($tooLarge) {
                return ['status' => 'unavailable', 'reason' => 'AMP_OUTPUT_LIMIT: Amp returned more data than Molly can inspect in one read.', 'output' => ''];
            }
            $result = $process->wait();
            if ($result->failed()) {
                $reason = $result->exitCode() === 127
                    ? 'AMP_BINARY_UNAVAILABLE: Molly could not find the Amp executable.'
                    : (preg_match('/not logged in|unauthorized|not authenticated|authentication|invalid api key|amp login|\b401\b|\b403\b/i', $errors)
                        ? 'AMP_AUTHENTICATION_FAILED: Amp could not authenticate. Check the Amp login for this account.'
                        : 'AMP_READ_FAILED: Amp could not read connection data. Check the Amp login and network access.');

                return ['status' => 'unavailable', 'reason' => $reason, 'output' => ''];
            }

            return ['status' => 'ok', 'reason' => null, 'output' => $output];
        } catch (ProcessTimedOutException) {
            if ($tooLarge) {
                return ['status' => 'unavailable', 'reason' => 'AMP_OUTPUT_LIMIT: Amp returned more data than Molly can inspect in one read.', 'output' => ''];
            }

            return ['status' => 'timeout', 'reason' => 'AMP_TIMEOUT: The Amp read reached its time limit.', 'output' => $output];
        } catch (Throwable) {
            return ['status' => 'unavailable', 'reason' => 'AMP_PROCESS_UNAVAILABLE: Molly could not run the Amp executable.', 'output' => ''];
        } finally {
            $process?->stop(0.1);
        }
    }

    /** @param array<string, mixed> $report */
    private function unavailable(array $report, ?string $reason): array
    {
        $report['status'] = 'unavailable';
        $report['reason'] = $reason;
        foreach ($report['threads'] as &$thread) {
            $thread['reason'] = $reason;
        }

        return $report;
    }
}
