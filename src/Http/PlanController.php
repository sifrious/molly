<?php

namespace Sifrious\Molly\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Sifrious\Molly\Actions\AnswerPlan;
use Sifrious\Molly\Actions\CreatePlan;
use Sifrious\Molly\Actions\CreateTaskFromPlan;
use Sifrious\Molly\Actions\ListPlans;
use Sifrious\Molly\Actions\ShowPlan;
use Sifrious\Molly\Actions\SuggestPlanReview;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\PlanningGuide;

class PlanController
{
    public function index(ListPlans $list): View
    {
        return view('molly::plans', ['plans' => $list->handle()]);
    }

    public function suggest(string $plan, ShowPlan $show, SuggestPlanReview $suggest): RedirectResponse
    {
        abort_unless(config('molly.jev.enabled', false) === true && is_string(config('ai.providers.typesafe.key')) && trim(config('ai.providers.typesafe.key')) !== '', 403);
        $record = $show->handle($plan);
        abort_if($record === null, 404);
        $suggest->handle($record);

        return redirect()->route('molly.plans.show', $plan)->with('status', 'Jev review request finished. See the result below.');
    }

    public function create(): View
    {
        return view('molly::plan-create');
    }

    public function guide(PlanningGuide $guide): View
    {
        $graph = $guide->graph();
        $sources = array_map(fn (array $source): array => $guide->source($source['id']), $graph['sources']);
        $titles = [];
        foreach ($graph['steps'] as $step) {
            $titles['step:'.$step['id']] = $step['title'];
        }
        foreach ($sources as $source) {
            $titles['source:'.$source['id']] = $source['title'];
        }

        return view('molly::guide', ['graph' => $graph, 'sources' => $sources, 'titles' => $titles]);
    }

    public function store(Request $request, CreatePlan $create): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:1500'],
            'review_mode' => ['required', 'in:guided,skip'],
        ]);
        try {
            $plan = $create->handle($data['description'], $data['review_mode'] === 'guided');
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['description' => $exception->getMessage()]);
        }

        return redirect()->route('molly.plans.show', $plan->id)->with('status', 'Plan saved. No task has started.');
    }

    public function show(string $plan, ShowPlan $show, PlanningGuide $guide): View
    {
        $record = $show->handle($plan);
        abort_if($record === null, 404);

        return view('molly::plan', [
            'plan' => $record,
            'tasks' => Task::where('source->plan_id', $record->id)->latest()->get(),
            'canSuggest' => config('molly.jev.enabled', false) === true && is_string(config('ai.providers.typesafe.key')) && trim(config('ai.providers.typesafe.key')) !== '',
            'steps' => $guide->steps(),
            'nextStep' => $record->nextStep(),
            'sources' => $guide->sourcesFor($record->description, $record->answers ?? []),
            'sourceTitles' => array_column($guide->graph()['sources'], 'title', 'id'),
        ]);
    }

    public function answer(string $plan, Request $request, AnswerPlan $answer): RedirectResponse
    {
        $data = $request->validate([
            'step' => ['required', 'string', 'max:128'],
            'answer' => ['required', 'string', 'max:600'],
        ]);
        try {
            $answer->handle($plan, $data['step'], $data['answer']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['answer' => $exception->getMessage()]);
        }

        return redirect()->route('molly.plans.show', $plan)->with('status', 'Answer saved.');
    }

    public function task(string $plan, Request $request, CreateTaskFromPlan $create): RedirectResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:1000'],
            'nickname' => ['nullable', 'string', 'max:64'],
            'workspace' => ['required', 'string', 'max:4096'],
            'paths' => ['nullable', 'string', 'max:32768'],
            'test_path' => ['required', 'string', 'max:4096'],
            'allow_test_edits' => ['sometimes', 'boolean'],
        ]);
        $paths = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $data['paths'] ?? ''))));
        try {
            $task = $create->handle($plan, $data['prompt'], $data['workspace'], $paths, $data['test_path'], nickname: $data['nickname'] ?? null, allowTestEdits: $request->boolean('allow_test_edits'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['task' => $exception->getMessage()]);
        }

        return redirect()->route('molly.tasks.show', $task->id)->with('status', 'Task saved from the plan. No run has started.');
    }
}
