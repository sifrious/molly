<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Models\Plan;
use Sifrious\Molly\PlanningGuide;

class SuggestPlanReview
{
    public function __construct(private EvaluateWithTypeSafe $evaluate, private PlanningGuide $guide) {}

    /** @return array<string, mixed> */
    public function handle(Plan $plan): array
    {
        $plan->refresh();
        $evidence = ['description' => $plan->description, 'answers' => $plan->answers, 'guide_version' => $plan->guide_version];
        if ($plan->guide_version !== $this->guide->version()) {
            $result = ['status' => 'needs_review', 'focus' => null, 'confidence' => null, 'reason' => 'guide_changed', 'provider' => 'typesafe'];
        } else {
            $evidence['sources'] = array_map(fn (array $source): array => [
                'id' => $source['id'], 'snippet' => mb_strcut($source['content'], 0, 3000, 'UTF-8'),
            ], $this->guide->sourcesFor($plan->description, $plan->answers));
            $result = $this->evaluate->planning($evidence);
        }
        $result['guide_version'] = $plan->guide_version;
        $result['question'] = null;
        $result['sources'] = [];
        if ($result['status'] === 'evaluated') {
            foreach ($this->guide->steps() as $step) {
                if ($step['id'] === $result['focus']) {
                    $result['question'] = $step['question'];
                    $result['sources'] = array_map(function (string $id): array {
                        $source = $this->guide->source($id);

                        return array_intersect_key($source, array_flip(['id', 'title', 'url', 'revision', 'path']));
                    }, $step['source_ids']);
                    break;
                }
            }
        }
        $result['evaluated_at'] = now()->toIso8601String();
        $result['answers_at_evaluation'] = $plan->answers;
        $plan->update(['suggestion' => $result]);

        return $result;
    }
}
