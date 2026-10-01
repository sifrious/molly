<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Models\Run;
use Throwable;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;

class MollyRunCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:run {prompt? : What should Molly work on?} {--workspace= : Repository path} {--file=* : Repository-relative file Molly may change} {--test= : Pest test file that must pass} {--json : Print JSON only}';

    protected $description = 'Make a bounded local change and report tests and complexity';

    public function handle(RunTask $action, RunReport $report): int
    {
        $json = (bool) $this->option('json');
        try {
            $prompt = trim((string) $this->argument('prompt'));
            if ($prompt === '') {
                if ($json || ! $this->input->isInteractive()) {
                    throw new InvalidArgumentException('PROMPT_REQUIRED: Pass the task as the first argument when using --json or --no-interaction, for example php artisan molly:run "Return Hello".');
                }
                $prompt = text('What should Molly work on?', required: 'Describe the change Molly should make.', transform: fn (string $value): string => trim($value));
            }
            $test = trim((string) $this->option('test'));
            if ($test === '') {
                throw new InvalidArgumentException('TEST_REQUIRED: Use --test to name the Pest test file that must pass, for example --test=tests/Feature/GreetingTest.php.');
            }
            $workspace = (string) ($this->option('workspace') ?: base_path());
            if (! $json) {
                intro('Molly');
                note('Molly will check tests and review unnecessary complexity before completing the task.');
            }
            $run = $this->offeringChoices(fn (): Run => $action->handle($prompt, $workspace, $this->option('file'), trim((string) $this->option('test')), $json ? null : fn (string $message) => note($message)));
            if ($json) {
                $this->writeJson(['id' => $run->id, 'status' => $run->status, 'report' => $run->report]);
            } else {
                $report->show($run, $this->output->isVerbose());
            }

            return $run->status === 'completed' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            [$message, $choices] = $this->failureDetails($exception);

            return $this->reportFailure($message, ['id' => null, 'status' => 'failed', 'report' => ['error' => $message, ...$choices]]);
        }
    }
}
