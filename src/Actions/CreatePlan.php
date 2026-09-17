<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Models\Plan;
use Sifrious\Molly\PlanningGuide;

class CreatePlan
{
    public function __construct(private PlanningGuide $guide) {}

    public function handle(string $description, bool $guided = true): Plan
    {
        $description = trim($description);
        if ($description === '' || strlen($description) > 1500 || ! mb_check_encoding($description, 'UTF-8')) {
            throw new RuntimeException('PLAN_DESCRIPTION_INVALID: Describe the project or feature in 1 to 1500 UTF-8 bytes.');
        }

        return Plan::create([
            'description' => $description,
            'review_mode' => $guided ? 'guided' : 'skip',
            'answers' => [],
            'guide_version' => $this->guide->version(),
        ])->fresh();
    }
}
