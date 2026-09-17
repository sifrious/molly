<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\LinkTaskThread;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyLinkThreadCommand extends Command
{
    protected $signature = 'molly:link-thread {task : Saved task name or ID} {thread : Amp thread ID beginning with T-} {--json : Print JSON only}';

    protected $description = 'Record an Amp thread association without starting work';

    public function handle(LinkTaskThread $link): int
    {
        try {
            $result = $link->handle((string) $this->argument('task'), (string) $this->argument('thread'));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                note('Thread linked. Read current connection status with php artisan molly:connections '.$this->argument('task').'.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
