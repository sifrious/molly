<?php

namespace Sifrious\Molly\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use RuntimeException;

class Task extends Model
{
    use HasUuids;

    protected $table = 'molly_tasks';

    protected $fillable = ['nickname', 'prompt', 'workspace', 'paths', 'test_path', 'test_digest', 'allow_test_edits', 'status', 'source', 'stop_requested_at', 'context_snapshot', 'journal_status', 'worker_id', 'claimed_at', 'lease_expires_at', 'heartbeat_at', 'attempt_number', 'idempotency_key', 'parent_run_id', 'project_id', 'workspace_id', 'repository_id', 'repository_remote_identity', 'checkout_id', 'checkout_kind', 'base_sha', 'branch', 'bloom_workspace_id', 'identity_status'];

    protected $attributes = ['status' => 'pending'];

    public static function findByReference(string $reference): ?self
    {
        $reference = strtolower(trim($reference));

        return Str::isUuid($reference)
            ? static::find($reference)
            : static::where('nickname', $reference)->first();
    }

    public function reference(): string
    {
        return $this->nickname ?? $this->id;
    }

    public static function validateNickname(string $nickname, ?string $exceptId = null): string
    {
        $nickname = strtolower(trim($nickname));
        if (! preg_match('/\A[a-z][a-z0-9-]{0,63}\z/', $nickname) || Str::isUuid($nickname)) {
            throw new RuntimeException('TASK_NAME_INVALID: Use 1 to 64 letters, numbers, or hyphens, starting with a letter. UUIDs cannot be task names.');
        }

        $query = static::where('nickname', $nickname);
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }
        if ($query->exists()) {
            throw new RuntimeException('TASK_NAME_TAKEN: Another task already uses that name.');
        }

        return $nickname;
    }

    protected function casts(): array
    {
        return ['paths' => 'array', 'allow_test_edits' => 'boolean', 'source' => 'array', 'stop_requested_at' => 'datetime', 'context_snapshot' => 'array', 'journal_status' => 'array', 'claimed_at' => 'datetime', 'lease_expires_at' => 'datetime', 'heartbeat_at' => 'datetime', 'attempt_number' => 'integer'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class)->oldest()->orderBy('id');
    }
}
