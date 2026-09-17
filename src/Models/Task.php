<?php

namespace Sifrious\Molly\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasUuids;

    protected $table = 'molly_tasks';

    protected $fillable = ['prompt', 'workspace', 'paths', 'test_path', 'status', 'source', 'stop_requested_at'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['paths' => 'array', 'source' => 'array', 'stop_requested_at' => 'datetime'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class)->oldest()->orderBy('id');
    }
}
