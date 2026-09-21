<?php

namespace Sifrious\Molly\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Run extends Model
{
    use HasUuids;

    protected $table = 'molly_runs';

    protected $fillable = ['task_id', 'prompt', 'workspace', 'status', 'report', 'effective_config', 'project_id', 'workspace_id', 'repository_id', 'repository_remote_identity', 'checkout_id', 'checkout_kind', 'base_sha', 'branch', 'bloom_workspace_id', 'identity_status'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    protected function casts(): array
    {
        return ['report' => 'array', 'effective_config' => 'array'];
    }
}
