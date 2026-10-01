<?php

namespace Sifrious\Molly\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A registered local execution target. The UUID is the Orb's identity; the name is a label
 * people type. current_task_id reserves the Orb for one task from placement until that
 * task's attempt ends, so an Orb takes one task at a time. A reservation stays active while
 * its task exists and has not completed: a failed or stopped task holds an Orb only while a
 * retry is queued on it, because every attempt frees its Orb when it ends.
 */
class Orb extends Model
{
    use HasUuids;

    /** Task statuses whose reservation still holds an Orb. */
    public const HOLDING_STATUSES = ['pending', 'running', 'failed', 'stopped'];

    protected $table = 'molly_orbs';

    protected $fillable = ['name', 'provider', 'device', 'runtime', 'model', 'repository_path', 'repository_git_dir', 'repository_remote_identity', 'worktree_root', 'health', 'health_reason', 'runtime_identity', 'health_checked_at', 'heartbeat_at', 'current_task_id', 'reserved_at', 'reserved_prompt_sha256', 'revoked_at', 'revoked_reason'];

    public static function findByReference(string $reference): ?self
    {
        $reference = strtolower(trim($reference));

        return Str::isUuid($reference)
            ? static::find($reference)
            : static::where('name', $reference)->first();
    }

    /** The queue that carries this Orb's queued starts and retries. */
    public static function queueName(string $id): string
    {
        return 'molly-orb-'.strtolower($id);
    }

    public function queue(): string
    {
        return self::queueName($this->id);
    }

    /** Clear the reservation a task holds on any Orb. Returns the number of Orbs released. */
    public static function releaseTask(string $taskId): int
    {
        return static::where('current_task_id', $taskId)->update(['current_task_id' => null, 'reserved_at' => null, 'reserved_prompt_sha256' => null]);
    }

    /** The task that holds this Orb, or null when the Orb is free. */
    public function activeTask(): ?Task
    {
        if ($this->current_task_id === null) {
            return null;
        }

        return Task::whereKey($this->current_task_id)->whereIn('status', self::HOLDING_STATUSES)->first();
    }

    /** available, busy, unhealthy, or revoked. */
    public function availability(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->activeTask() !== null => 'busy',
            $this->health !== 'healthy' => 'unhealthy',
            default => 'available',
        };
    }

    protected function casts(): array
    {
        return ['runtime_identity' => 'array', 'health_checked_at' => 'datetime', 'heartbeat_at' => 'datetime', 'reserved_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
