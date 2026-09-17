<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Sifrious\Molly\Models\Plan;
use Sifrious\Molly\PlanningGuide;

class AnswerPlan
{
    public function __construct(private PlanningGuide $guide) {}

    public function handle(string $id, string $step, string $answer): Plan
    {
        $answer = trim($answer);
        if ($answer === '' || strlen($answer) > 600 || ! mb_check_encoding($answer, 'UTF-8')) {
            throw new RuntimeException('PLAN_ANSWER_INVALID: Answer in 1 to 600 UTF-8 bytes.');
        }

        return DB::transaction(function () use ($id, $step, $answer): Plan {
            $plan = Plan::lockForUpdate()->find($id);
            if ($plan === null) {
                throw new RuntimeException('PLAN_NOT_FOUND: Molly could not find that plan.');
            }
            if ($plan->guide_version !== $this->guide->version()) {
                throw new RuntimeException('PLAN_GUIDE_CHANGED: Start a new plan to use the updated planning guide. Your saved answers remain available.');
            }
            if (($plan->answers[$step] ?? null) === $answer) {
                return $plan;
            }
            if ($plan->review_mode !== 'guided' || ($plan->nextStep()['id'] ?? null) !== $step) {
                throw new RuntimeException('PLAN_STEP_INVALID: Answer the current question. Saved decisions cannot be overwritten.');
            }
            $plan->update(['answers' => [...$plan->answers, $step => $answer]]);

            return $plan;
        });
    }
}
