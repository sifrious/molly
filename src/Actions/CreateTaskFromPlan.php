<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Models\Plan;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\PlanningGuide;

class CreateTaskFromPlan
{
    public function __construct(private CreateTask $create, private PlanningGuide $guide) {}

    /** @param list<string> $paths */
    public function handle(string $id, string $prompt, string $workspace, array $paths, string $testPath, ?string $nickname = null): Task
    {
        $plan = Plan::find($id);
        if ($plan === null) {
            throw new RuntimeException('PLAN_NOT_FOUND: Molly could not find that plan.');
        }
        if ($plan->guide_version !== $this->guide->version()) {
            throw new RuntimeException('PLAN_GUIDE_CHANGED: Start a new plan to use the updated planning guide.');
        }
        if (! $plan->completed()) {
            throw new RuntimeException('PLAN_INCOMPLETE: Finish the guided review before creating tasks.');
        }
        $prompt = trim($prompt);
        if ($prompt === '' || strlen($prompt) > 1000) {
            throw new RuntimeException('PLAN_TASK_INVALID: Describe one task in 1 to 1000 bytes.');
        }
        $citations = array_map(fn (array $source): array => array_intersect_key($source, array_flip(['id', 'title', 'url', 'revision', 'sha256'])), $this->guide->sourcesFor($plan->description, $plan->answers));
        $context = ['description' => $plan->description, 'review_mode' => $plan->review_mode, 'decisions' => $plan->answers];
        $taskPrompt = $prompt."\n\nPlanning decisions supplied by the user:\n".json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->create->handle($taskPrompt, $workspace, $paths, $testPath, [
            'provider' => 'molly-plan', 'plan_id' => $plan->id, 'guide_version' => $plan->guide_version,
            'planning' => $context, 'citations' => $citations,
        ], nickname: $nickname);
    }
}
