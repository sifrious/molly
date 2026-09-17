<?php

namespace Sifrious\Molly\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Sifrious\Molly\PlanningGuide;

class Plan extends Model
{
    use HasUuids;

    protected $table = 'molly_plans';

    protected $fillable = ['description', 'review_mode', 'answers', 'guide_version', 'suggestion'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'suggestion' => 'array'];
    }

    public function completed(): bool
    {
        return $this->review_mode === 'skip' || $this->nextStep() === null;
    }

    /** @return array<string, mixed>|null */
    public function nextStep(): ?array
    {
        return $this->review_mode === 'skip' ? null : app(PlanningGuide::class)->nextStep($this->answers);
    }
}
