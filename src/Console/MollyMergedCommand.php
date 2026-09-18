<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\RecordMerged;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyMergedCommand extends Command
{
    protected $signature = 'molly:merged {task : Saved task name or ID} {--sha= : 40-character merge commit SHA} {--approve : Confirm recording the merge} {--json : Print JSON only}';

    protected $description = 'Record that a human merged a pull request without merging from Molly';

    public function handle(RecordMerged $record): int
    {
        try {
            $result = $record->handle((string) $this->argument('task'), (bool) $this->option('approve'), (string) $this->option('sha'));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                note('Recorded merge '.$result['merge_sha'].' for '.$result['task_id'].'. Molly did not merge it.');
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
