<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\StartTask;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Throwable;

use function Laravel\Prompts\note;

class MollyStartCommand extends Command implements SignalableCommandInterface
{
    use ReportsFailures;
    use StopsRunOnSignal;

    protected $signature = 'molly:start {task : Saved task name or ID} {--json : Print JSON only}';

    protected $description = 'Start a saved task and report tests and complexity';

    public function handle(StartTask $action, RunReport $report): int
    {
        $this->runningAction = $action;
        try {
            $run = $action->handle((string) $this->argument('task'), $this->option('json') ? null : fn (string $message) => note($message));
            if ($this->option('json')) {
                $this->line(json_encode(['id' => $run->id, 'task_id' => $run->task_id, 'status' => $run->status, 'report' => $run->report, ...array_filter(['next' => $report->next($run)])], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                $report->show($run, $this->output->isVerbose());
            }

            return $this->exitCodeAfterSignal($run->status === 'completed' ? self::SUCCESS : self::FAILURE, $run->status);
        } catch (Throwable $exception) {
            return $this->exitCodeAfterSignal($this->reportFailure($exception->getMessage(), ['id' => null, 'task_id' => (string) $this->argument('task'), 'status' => 'error', 'report' => ['error' => $exception->getMessage()]]));
        }
    }
}
