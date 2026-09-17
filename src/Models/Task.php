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

    protected $fillable = ['nickname', 'prompt', 'workspace', 'paths', 'test_path', 'status', 'source', 'stop_requested_at'];

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
        return ['paths' => 'array', 'source' => 'array', 'stop_requested_at' => 'datetime'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class)->oldest()->orderBy('id');
    }
}
