<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\ComposePullRequestBody;
use Throwable;

class MollyPrBodyCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:pr-body {task : Saved task name or ID} {--close : Include Closes language after required checks pass} {--json : Print JSON only}';

    protected $description = 'Print a pull request body that links the issue, acceptance test, and Molly evidence';

    public function handle(ComposePullRequestBody $compose): int
    {
        try {
            $result = $compose->handle((string) $this->argument('task'), (bool) $this->option('close'));
            if ($this->option('json')) {
                $this->writeJson($result);
            } else {
                $this->line($result['body']);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['status' => 'error', 'error' => $exception->getMessage()]);
        }
    }
}
