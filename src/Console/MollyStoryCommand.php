<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Sifrious\Molly\Actions\CreateTaskFromStory;
use Sifrious\Molly\Models\Task;
use Throwable;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;
use function Laravel\Prompts\warning;

class MollyStoryCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:story {story? : Plain-English story for the feature} {--workspace= : Repository path} {--file=* : Repository-relative file the implementation may change} {--test= : Pest test file the authoring run writes} {--name= : Task nickname} {--json : Print JSON only}';

    protected $description = 'Derive acceptance criteria from a story and save a test-authoring task';

    public function handle(CreateTaskFromStory $action): int
    {
        try {
            $interactive = ! $this->option('json') && $this->input->isInteractive();
            $story = trim((string) $this->argument('story'));
            if ($story === '') {
                if (! $interactive) {
                    throw new InvalidArgumentException('STORY_REQUIRED: Pass the story as the first argument when using --json or --no-interaction.');
                }
                $story = textarea('Describe the feature as a story', required: 'Describe what people should be able to do.', transform: trim(...));
            }
            $test = trim((string) $this->option('test'));
            if ($test === '') {
                if (! $interactive) {
                    throw new InvalidArgumentException('TEST_REQUIRED: Use --test to name the Pest test file the authoring run writes, for example --test=tests/Feature/StoryTest.php.');
                }
                $test = text('Which Pest test should Molly write?', placeholder: 'tests/Feature/StoryTest.php', required: true, transform: trim(...));
            }
            $paths = [];
            $task = $this->offeringChoices(function () use ($action, $story, $test, &$paths): Task {
                $paths = array_values(array_filter(array_map(trim(...), $this->option('file'))));

                return $action->handle($story, (string) ($this->option('workspace') ?: base_path()), $paths, trim((string) $this->option('test')) ?: $test, $this->option('name'));
            });
            $reference = $task->reference();
            $packages = array_values(array_filter($task->source['scope']['required_packages'], fn (array $package): bool => $package['command'] !== null));
            // The lock uses the task's files and the derived scope, so the printed command needs no --file.
            $next = ['php artisan molly:start '.$reference, 'php artisan molly:lock-test '.$reference.' --approve', 'php artisan molly:start '.$reference];

            if ($this->option('json')) {
                $this->writeJson([
                    'id' => $task->id,
                    'status' => $task->status,
                    'acceptance' => $task->source['acceptance'],
                    'scope' => $task->source['scope'],
                    'package_commands' => array_column($packages, 'command'),
                    'next' => $next,
                    'task' => $task->toArray(),
                ]);

                return self::SUCCESS;
            }

            info('Acceptance criteria');
            $this->line($task->source['acceptance']['text']);
            $scope = $task->source['scope'];
            info('Implementation files');
            $this->line(implode("\n", [...$paths, ...$scope['files']]));
            foreach ($scope['adjusted'] ?? [] as $adjusted) {
                note($adjusted['from'] === null
                    ? 'Molly added '.$adjusted['path'].'. '.$adjusted['reason']
                    : 'Molly moved '.$adjusted['from'].' to '.$adjusted['path'].'. '.$adjusted['reason']);
            }
            foreach ($scope['rejected'] as $rejected) {
                warning('Molly left out '.$rejected['path'].'. '.$rejected['reason']);
            }
            foreach ($scope['rejected_packages'] as $rejected) {
                warning('Molly left out the package '.$rejected['name'].'. '.$rejected['reason']);
            }
            if ($packages !== []) {
                warning('The implementation run cannot add Composer packages. Run these in '.$task->workspace.' before step 3.');
                foreach ($packages as $package) {
                    $this->line($package['command'].' ('.$package['name'].' '.match ($package['status']) {
                        'dev_only' => 'is installed for development only',
                        'transitive' => 'is installed only as a dependency of another package',
                        default => 'is not installed',
                    }.')');
                }
            }
            note('Saved test-authoring task '.$reference.'. Review the criteria, then run these commands in order.');
            $this->line('1. '.$next[0].' writes the Pest test.');
            $this->line('2. '.$next[1].' locks it, allows the implementation files above, and records the RED baseline.');
            $this->line('3. '.$next[2].' implements against the locked test.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportException($exception, ['id' => null, 'status' => 'error']);
        }
    }
}
