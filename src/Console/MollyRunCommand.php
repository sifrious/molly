<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Models\Run;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;

class MollyRunCommand extends Command
{
    protected $signature = 'molly:run {prompt? : What should Molly work on?} {--workspace= : Repository path} {--file=* : Repository-relative file Molly may change} {--test= : Repository-relative Pest test file} {--json : Print JSON only}';

    protected $description = 'Make a bounded local change and report tests and complexity';

    public function handle(RunTask $action, RunReport $report): int
    {
        $json = (bool) $this->option('json');
        try {
            $prompt = trim((string) $this->argument('prompt'));
            if ($prompt === '') {
                if ($json || ! $this->input->isInteractive()) {
                    throw new InvalidArgumentException('Provide a prompt when using --json or --no-interaction.');
                }
                $prompt = text('What should Molly work on?', required: 'Describe the change Molly should make.', transform: fn (string $value): string => trim($value));
            }
            $paths = $this->option('file');
            if ($paths === [] || in_array('', $paths, true)) {
                throw new InvalidArgumentException('Use --file for each file Molly may change.');
            }
            $test = trim((string) $this->option('test'));
            if ($test === '') {
                throw new InvalidArgumentException('Use --test to name the Pest test file that must pass.');
            }
            $workspace = (string) ($this->option('workspace') ?: base_path());
            if (! $json) {
                intro('Molly');
                note('Molly will check tests and review unnecessary complexity before completing the task.');
            }
            $work = fn (): Run => $action->handle($prompt, $workspace, $paths, $test, $json ? null : fn (string $message) => note($message));
            $run = $work();
            if ($json) {
                $this->writeJson(['id' => $run->id, 'status' => $run->status, 'report' => $run->report]);
            } else {
                $report->show($run, $this->output->isVerbose());
            }

            return $run->status === 'completed' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            if ($json) {
                $this->writeJson(['id' => null, 'status' => 'failed', 'report' => ['error' => $exception->getMessage()]]);
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }

    /** @param array<string, mixed> $value */
    private function writeJson(array $value): void
    {
        $this->line(json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
    }
}
