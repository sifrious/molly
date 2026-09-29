<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\RetryTask;
use Sifrious\Molly\Contracts\ExecutionTargetRequest;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Throwable;

use function Laravel\Prompts\note;

class MollyRetryCommand extends Command implements SignalableCommandInterface
{
    use ReportsFailures;
    use StopsRunOnSignal;

    protected $signature = 'molly:retry
        {task : Saved task name or ID}
        {--orb= : Run on this registered Orb, by name or ID}
        {--orb-runtime= : Run on an idle Orb with this runtime, ollama or amp}
        {--orb-model= : Run on an idle Orb with this model}
        {--json : Print JSON only}';

    protected $description = 'Retry a saved task and report tests and complexity';

    public function handle(RetryTask $action, RunReport $report): int
    {
        $this->runningAction = $action;
        try {
            $target = ExecutionTargetRequest::orb($this->option('orb'), $this->option('orb-runtime'), $this->option('orb-model'));
            $progress = $this->option('json') ? null : fn (string $message) => note($message);
            $run = $target === null
                ? $action->handle((string) $this->argument('task'), $progress)
                : $action->handle((string) $this->argument('task'), $progress, $target);
            if ($this->option('json')) {
                $this->writeJson(['id' => $run->id, 'task_id' => $run->task_id, 'status' => $run->status, 'report' => $run->report, ...array_filter(['next' => $report->next($run)])]);
            } else {
                $report->show($run, $this->output->isVerbose());
            }

            return $this->exitCodeAfterSignal($run->status === 'completed' ? self::SUCCESS : self::FAILURE, $run->status);
        } catch (Throwable $exception) {
            return $this->exitCodeAfterSignal($this->reportFailure($exception->getMessage(), ['id' => null, 'task_id' => (string) $this->argument('task'), 'status' => 'error', 'report' => ['error' => $exception->getMessage()]]));
        }
    }
}
