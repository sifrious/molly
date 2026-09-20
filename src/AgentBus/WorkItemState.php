<?php

namespace Sifrious\Molly\AgentBus;

/**
 * Explicit agent-bus work-item states. Mapped onto molly_tasks.status for v0.1-narrow local mode.
 */
enum WorkItemState: string
{
    case Queued = 'queued';
    case Claimed = 'claimed';
    case Running = 'running';
    case Waiting = 'waiting';
    case Verifying = 'verifying';
    case Completed = 'completed';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Retryable = 'retryable';

    public function toTaskStatus(): string
    {
        return match ($this) {
            self::Queued, self::Waiting => 'pending',
            self::Claimed, self::Running, self::Verifying => 'running',
            self::Completed => 'completed',
            self::Failed, self::Retryable => 'failed',
            self::Canceled => 'stopped',
        };
    }

    public static function fromTaskStatus(string $status): self
    {
        return match ($status) {
            'pending' => self::Queued,
            'running' => self::Running,
            'completed' => self::Completed,
            'failed' => self::Failed,
            'stopped' => self::Canceled,
            default => self::Failed,
        };
    }
}
