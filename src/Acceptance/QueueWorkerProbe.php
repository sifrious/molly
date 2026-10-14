<?php

namespace Sifrious\Molly\Acceptance;

use RuntimeException;
use Sifrious\Molly\Actions\ManageWorker;

/**
 * Reads the molly:worker queue worker for one workspace.
 * Production calls ManageWorker. A test can supply the worker record and the process
 * command line; the same alive-and-owns-the-command rule still decides the state.
 */
final class QueueWorkerProbe
{
    /**
     * @param  array{pid: int, pgid: int, command: list<string>}|null  $record
     */
    public function __construct(
        private ManageWorker $workers,
        private ?array $record = null,
        private ?bool $alive = null,
        private ?string $commandLine = null,
        private ?int $pgid = null,
    ) {}

    /** @return array<string, mixed> */
    public function status(string $workspace): array
    {
        if ($this->record === null) {
            return $this->workers->status($workspace);
        }

        $command = $this->record['command'] ?? null;
        $pid = $this->record['pid'] ?? null;
        $pgid = $this->record['pgid'] ?? null;
        if (! is_array($command) || $command === [] || ! is_int($pid) || $pid < 2 || ! is_int($pgid)) {
            throw new RuntimeException('WORKER_RECORD_INVALID: the supplied worker record is not a Molly worker record.');
        }
        $expected = implode(' ', array_slice($command, 1));
        $owned = $this->alive === true
            && $expected !== ''
            && is_string($this->commandLine)
            && str_contains($this->commandLine, $expected)
            && $this->pgid === $pgid;

        return [
            'state' => $owned ? 'running' : 'stale',
            'command' => $command,
            'pid' => $pid,
            'pgid' => $pgid,
            'pid_file' => rtrim($workspace, '/').'/.molly/worker/worker.json',
            'stale_reason' => $owned ? null : ($this->alive === true ? 'pid_reused' : 'process_gone'),
        ];
    }
}
