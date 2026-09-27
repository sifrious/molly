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

    /**
     * Attempts counted against molly.max_attempts. Locking an authored Pest
     * test starts the implementation scope, so runs made while authoring the
     * test are not charged to the implementation.
     */
    public function attemptsUsed(): int
    {
        $before = $this->source['test_lock']['runs_before'] ?? 0;

        return max(0, $this->runs()->count() - (is_int($before) ? $before : 0));
    }

    /**
     * Why an implementation run may not start yet, or null when it may.
     * A task whose Pest test was authored and locked needs a RED baseline
     * that failed for missing behavior, unless it opted out at creation.
     */
    public function redBaselineError(): ?string
    {
        $lock = $this->source['test_lock'] ?? null;
        if ($this->allow_test_edits || ! is_array($lock) || ($this->source['red_baseline_required'] ?? true) === false) {
            return null;
        }

        $baseline = $lock['red_baseline'] ?? null;
        if (! is_array($baseline) || ! is_string($baseline['classification'] ?? null)) {
            return 'RED_BASELINE_MISSING: Molly has no RED run of the locked Pest test. Run molly:lock-test --approve to record one before implementation.';
        }
        if ($baseline['classification'] !== 'missing_behavior') {
            return 'RED_BASELINE_INVALID: The locked Pest test run was classified as '.$baseline['classification']
                .(is_string($baseline['reason'] ?? null) ? ' ('.$baseline['reason'].')' : '')
                .'. Fix the test so it fails only for missing behavior, then run molly:lock-test --approve again.';
        }
        if (($baseline['test_digest'] ?? null) !== $this->test_digest) {
            return 'RED_BASELINE_INVALID: The RED baseline was recorded against a different test digest. Run molly:lock-test --approve again.';
        }

        return null;
    }
}
