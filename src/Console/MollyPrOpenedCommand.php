<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\RecordPullRequestOpened;
use Throwable;

use function Laravel\Prompts\note;

class MollyPrOpenedCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:pr-opened {task : Saved task name or ID} {--url= : HTTPS github.com pull request URL} {--approve : Confirm recording the opened pull request} {--json : Print JSON only}';

    protected $description = 'Record that a human opened a pull request without opening one from Molly';

    public function handle(RecordPullRequestOpened $record): int
    {
        try {
            $result = $record->handle((string) $this->argument('task'), (bool) $this->option('approve'), (string) $this->option('url'));
            if ($this->option('json')) {
                $this->writeJson($result);
            } else {
                note('Recorded pull request '.$result['pull_request_url'].' for '.$result['task_id'].'. Molly did not open it.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['status' => 'error', 'error' => $exception->getMessage()]);
        }
    }
}
