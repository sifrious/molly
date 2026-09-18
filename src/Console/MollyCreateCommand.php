<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Sifrious\Molly\Actions\CreateTask;
use Sifrious\Molly\Models\Task;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;

class MollyCreateCommand extends Command
{
    protected $signature = 'molly:create {prompt? : What should Molly work on?} {--workspace= : Repository path} {--file=* : Repository-relative file Molly may change} {--test= : Pest test file that must pass} {--allow-test-edits : Permit this task to change the required Pest test} {--name= : Task nickname} {--json : Print JSON only}';

    protected $description = 'Save a task without running the model or changing files';

    public function handle(CreateTask $action, TaskReport $report): int
    {
        try {
            $interactive = ! $this->option('json') && $this->input->isInteractive();
            $guided = $interactive && (trim((string) $this->argument('prompt')) === '' || ! $this->option('test') || $this->option('file') === []);
            $prompt = $this->taskPrompt($interactive);
            $nickname = $this->taskNickname($guided);
            [$paths, $test] = $this->taskFiles($interactive);
            $task = $action->handle($prompt, (string) ($this->option('workspace') ?: base_path()), $paths, $test, nickname: $nickname, allowTestEdits: (bool) $this->option('allow-test-edits'));
            if ($this->option('json')) {
                $this->line(json_encode(['id' => $task->id, 'status' => $task->status, 'task' => $task->toArray()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                $report->show($task);
                note('Start this task with php artisan molly:start '.$task->reference().'.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['id' => null, 'status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }

    private function taskPrompt(bool $interactive): string
    {
        $prompt = trim((string) $this->argument('prompt'));
        if ($prompt !== '') {
            return $prompt;
        }
        if (! $interactive) {
            throw new InvalidArgumentException('Provide a prompt when using --json or --no-interaction.');
        }

        return text('What should Molly work on?', required: 'Describe the change Molly should make.', transform: trim(...));
    }

    private function taskNickname(bool $guided): ?string
    {
        $nickname = $this->option('name');
        if ($guided && $nickname === null) {
            $nickname = text(
                'Task nickname',
                placeholder: 'health-check',
                hint: 'Optional. Use this name in task commands, or leave blank to use the ID.',
                validate: function (string $value): ?string {
                    try {
                        if (trim($value) !== '') {
                            Task::validateNickname($value);
                        }
                    } catch (\RuntimeException $exception) {
                        return $exception->getMessage();
                    }

                    return null;
                },
                transform: trim(...),
            );
        }

        return $nickname === null || trim($nickname) === '' ? null : trim($nickname);
    }

    /** @return array{list<string>, string} */
    private function taskFiles(bool $interactive): array
    {
        $test = trim((string) $this->option('test'));
        if ($test === '') {
            if (! $interactive) {
                throw new InvalidArgumentException('Use --test to name the Pest test file that must pass.');
            }
            $test = text('Which Pest test should pass?', placeholder: 'tests/Feature/HealthTest.php', required: true, hint: 'This test is read-only unless you pass --allow-test-edits.', transform: trim(...));
        }
        $paths = $this->option('file');
        if ($paths === [] && $interactive) {
            $answer = text('Which files may Molly change?', placeholder: 'routes/web.php, app/Models/User.php', hint: 'Separate paths with commas. Do not include the required Pest test.', transform: trim(...));
            $paths = $answer === '' ? [] : array_map(trim(...), explode(',', $answer));
        }

        return [$paths, $test];
    }
}
