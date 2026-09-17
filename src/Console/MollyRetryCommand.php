<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\RetryTask;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyRetryCommand extends Command
{
    protected $signature = 'molly:retry {task : Saved task name or ID} {--json : Print JSON only}';

    protected $description = 'Retry a saved task and report tests and complexity';

    public function handle(RetryTask $action, RunReport $report): int
    {
        try {
            $run = $action->handle((string) $this->argument('task'), $this->option('json') ? null : fn (string $message) => note($message));
            if ($this->option('json')) {
                $this->line(json_encode(['id' => $run->id, 'task_id' => $run->task_id, 'status' => $run->status, 'report' => $run->report], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                $report->show($run, $this->output->isVerbose());
            }

            return $run->status === 'completed' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['id' => null, 'task_id' => (string) $this->argument('task'), 'status' => 'error', 'report' => ['error' => $exception->getMessage()]], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
