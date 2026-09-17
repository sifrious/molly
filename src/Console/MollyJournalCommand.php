<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\ExportTaskJournal;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyJournalCommand extends Command
{
    protected $signature = 'molly:journal {task : Saved task name or ID} {--json : Print JSON only}';

    protected $description = 'Export saved task evidence to a local Markdown journal';

    public function handle(ExportTaskJournal $action): int
    {
        try {
            $result = $action->handle((string) $this->argument('task'));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                note('Journal saved: '.$result['path']);
                note($result['attempt_count'].' saved '.($result['attempt_count'] === 1 ? 'attempt.' : 'attempts.'));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['task' => (string) $this->argument('task'), 'status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
