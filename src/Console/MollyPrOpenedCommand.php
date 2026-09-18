<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\RecordPullRequestOpened;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyPrOpenedCommand extends Command
{
    protected $signature = 'molly:pr-opened {task : Saved task name or ID} {--url= : HTTPS github.com pull request URL} {--approve : Confirm recording the opened pull request} {--json : Print JSON only}';

    protected $description = 'Record that a human opened a pull request without opening one from Molly';

    public function handle(RecordPullRequestOpened $record): int
    {
        try {
            $result = $record->handle((string) $this->argument('task'), (bool) $this->option('approve'), (string) $this->option('url'));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                note('Recorded pull request '.$result['pull_request_url'].' for '.$result['task_id'].'. Molly did not open it.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
