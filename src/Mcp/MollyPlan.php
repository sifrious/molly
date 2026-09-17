<?php

namespace Sifrious\Molly\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use RuntimeException;
use Sifrious\Molly\Actions\AnswerPlan;
use Sifrious\Molly\Actions\CreatePlan;
use Sifrious\Molly\Actions\ShowPlan;
use Sifrious\Molly\Actions\SuggestPlanReview;
use Sifrious\Molly\PlanningGuide;

#[Name('molly_plan')]
#[Description('Create, read, answer, or request a TypeSafe suggestion for a saved plan. A suggestion records advice and does not answer a question. Guided plans ask five ordered questions before tasks can be created. guided=false explicitly skips that review. Responses include the next question and cited offline passages. No task runs.')]
#[IsDestructive]
class MollyPlan extends Tool
{
    public function handle(Request $request, PlanningGuide $guide, CreatePlan $create, ShowPlan $show, AnswerPlan $answer, SuggestPlanReview $suggest): Response|ResponseFactory
    {
        $data = $request->validate([
            'operation' => ['required', 'in:create,show,answer,suggest'],
            'description' => ['required_if:operation,create', 'string', 'max:1500'],
            'guided' => ['sometimes', 'boolean'],
            'id' => ['required_if:operation,show,answer,suggest', 'string', 'max:100'],
            'step' => ['required_if:operation,answer', 'in:outcome,state,laravel,boundaries,verification'],
            'answer' => ['required_if:operation,answer', 'string', 'max:600'],
        ]);
        try {
            $plan = match ($data['operation']) {
                'create' => $create->handle($data['description'], $data['guided'] ?? true),
                'show', 'suggest' => $show->handle($data['id']) ?? throw new RuntimeException('PLAN_NOT_FOUND: Molly could not find that plan.'),
                'answer' => $answer->handle($data['id'], $data['step'], $data['answer']),
            };

            if ($data['operation'] === 'suggest') {
                $suggest->handle($plan);
                $plan->refresh();
            }

            return Response::structured([
                'plan' => $plan->toArray(),
                'completed' => $plan->completed(),
                'next_step' => $plan->nextStep(),
                'sources' => $guide->sourcesFor($plan->description, $plan->answers),
            ]);
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->enum(['create', 'show', 'answer', 'suggest'])->required(),
            'description' => $schema->string()->description('Project or feature, required for create; at most 1500 UTF-8 bytes.'),
            'guided' => $schema->boolean()->description('Defaults to true. Set false only to explicitly skip guided review.'),
            'id' => $schema->string()->description('Saved plan ID; required for show, answer, and suggest.'),
            'step' => $schema->string()->enum(['outcome', 'state', 'laravel', 'boundaries', 'verification'])->description('Current question ID; required for answer.'),
            'answer' => $schema->string()->description('User decision; required for answer; at most 600 UTF-8 bytes.'),
        ];
    }
}
