<?php

namespace Sifrious\Molly\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Sifrious\Molly\Seams\SeamError;

class SeamRevision extends Model
{
    use HasUuids;

    protected $table = 'molly_seam_revisions';

    protected $fillable = ['plan_id', 'seam_id', 'number', 'digest', 'snapshot', 'task_id', 'active_run_id', 'status', 'cursor', 'results'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'results' => 'array', 'number' => 'integer', 'cursor' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            if ($revision->isDirty(['plan_id', 'seam_id', 'number', 'digest', 'snapshot'])) {
                throw new SeamError('PLAN_REVISION_STALE', 'Saved instruction snapshots are immutable. Create a new revision.', $revision->id);
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
