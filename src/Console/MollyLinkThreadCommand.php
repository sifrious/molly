<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\LinkTaskThread;
use Throwable;

use function Laravel\Prompts\note;

class MollyLinkThreadCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:link-thread {task : Saved task name or ID} {thread : Amp thread ID beginning with T-} {--json : Print JSON only}';

    protected $description = 'Record an Amp thread association without starting work';

    public function handle(LinkTaskThread $link): int
    {
        try {
            $result = $link->handle((string) $this->argument('task'), (string) $this->argument('thread'));
            if ($this->option('json')) {
                $this->writeJson($result);
            } else {
                note('Thread linked. Read current connection status with php artisan molly:connections '.$this->argument('task').'.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['status' => 'error', 'error' => $exception->getMessage()]);
        }
    }
}
