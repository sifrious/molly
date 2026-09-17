<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Sifrious\Molly\Actions\AnswerPlan;
use Sifrious\Molly\Actions\CreatePlan;
use Sifrious\Molly\Actions\ShowPlan;
use Sifrious\Molly\PlanningGuide;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;

class MollyPlanCommand extends Command
{
    protected $signature = 'molly:plan {description? : Describe the project or collection of tasks}
        {--skip-review : Save the plan without guided questions}
        {--resume= : Resume a saved plan ID without changing its description}
        {--step= : Current step ID, used with --resume and --answer}
        {--answer= : Answer text for --step; does not prompt for further answers}
        {--json : Print the saved plan, next step, and source references as JSON}';

    protected $description = 'Save a plan and work through optional questions before creating tasks';

    public function handle(CreatePlan $create, ShowPlan $show, AnswerPlan $answer, PlanningGuide $guide): int
    {
        try {
            $resume = $this->option('resume');
            $step = $this->option('step');
            $response = $this->option('answer');
            $interactive = $this->input->isInteractive() && ! $this->option('json') && $response === null;
            if (($step === null) !== ($response === null) || ($step !== null && $resume === null)) {
                throw new InvalidArgumentException('Use --resume, --step, and --answer together to save an answer.');
            }
            if ($resume !== null) {
                if ($this->argument('description') !== null || $this->option('skip-review')) {
                    throw new InvalidArgumentException('Resume preserves the saved description and review choice. Omit the description and --skip-review.');
                }
                $plan = $show->handle((string) $resume);
                if ($plan === null) {
                    throw new InvalidArgumentException('PLAN_NOT_FOUND: No saved plan has that ID.');
                }
            } else {
                $description = trim((string) $this->argument('description'));
                if ($description === '') {
                    if (! $interactive) {
                        throw new InvalidArgumentException('Provide a description when using --json or --no-interaction.');
                    }
                    $description = text('What would you like to build?', required: 'Describe the project or collection of tasks.');
                }
                $guided = ! $this->option('skip-review');
                if ($guided && $interactive) {
                    $guided = confirm('Walk through the Tarpit review?', default: true);
                }
                $plan = $create->handle($description, $guided);
            }
            if ($step !== null) {
                $plan = $answer->handle($plan->id, (string) $step, (string) $response);
            }
            if ($interactive) {
                note('Plan '.$plan->id);
                note('Answers are saved after each question. Resume with php artisan molly:plan --resume='.$plan->id.'.');
                while (($next = $plan->nextStep()) !== null) {
                    note($next['title']);
                    note($next['help']);
                    $this->showSources($guide->sourcesFor($plan->description, $plan->answers ?? []));
                    $value = text($next['question'], required: 'Write an answer before continuing.');
                    $plan = $answer->handle($plan->id, $next['id'], $value);
                }
            }
            $sources = $guide->sourcesFor($plan->description, $plan->answers ?? []);
            if ($this->option('json')) {
                $this->line(json_encode([
                    'id' => $plan->id, 'status' => $plan->completed() ? 'ready' : 'draft',
                    'plan' => $plan->toArray(), 'next_step' => $plan->nextStep(), 'sources' => $sources,
                ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                note('Saved plan '.$plan->id.' / '.($plan->completed() ? 'Ready for tasks' : 'Review in progress'));
                if (($next = $plan->nextStep()) !== null) {
                    note('Next question: '.$next['question']);
                    note('Save an answer with --resume='.$plan->id.' --step='.$next['id'].' --answer="Your answer".');
                }
                if (! $interactive) {
                    $this->showSources($sources);
                }
                note('Planning saves your answers and source references. No model request or code execution has run.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['id' => $this->option('resume'), 'status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }

    /** @param list<array{id: string, title: string, url: string, revision: string, content: string}> $sources */
    private function showSources(array $sources): void
    {
        foreach ($sources as $source) {
            note($source['title'].' / '.$source['revision']);
            note(mb_strimwidth($source['content'], 0, 320, '...'));
            note($source['url']);
        }
    }
}
