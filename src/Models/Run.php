<?php

namespace Sifrious\Molly\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Run extends Model
{
    use HasUuids;

    protected $table = 'molly_runs';

    protected $fillable = ['prompt', 'workspace', 'status', 'report'];

    protected function casts(): array
    {
        return ['report' => 'array'];
    }
}
