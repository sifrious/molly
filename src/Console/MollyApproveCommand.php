<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\ApproveTask;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyApproveCommand extends Command
{
    protected $signature = 'molly:approve {task : Saved task name or ID} {--approve : Confirm human approval of the verified change} {--json : Print JSON only}';

    protected $description = 'Record human approval after required checks pass without opening a pull request';

    public function handle(ApproveTask $approve): int
    {
        try {
            $result = $approve->handle((string) $this->argument('task'), (bool) $this->option('approve'));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                note('Recorded human approval for '.$result['task_id'].'. Molly did not open a pull request.');
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
