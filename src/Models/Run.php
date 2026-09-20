<?php

namespace Sifrious\Molly\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Run extends Model
{
    use HasUuids;

    protected $table = 'molly_runs';

    protected $fillable = ['task_id', 'prompt', 'workspace', 'status', 'report', 'effective_config'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    protected function casts(): array
    {
        return ['report' => 'array', 'effective_config' => 'array'];
    }
}
