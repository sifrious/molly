<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\ComposePullRequestBody;
use Throwable;

use function Laravel\Prompts\error;

class MollyPrBodyCommand extends Command
{
    protected $signature = 'molly:pr-body {task : Saved task name or ID} {--close : Include Closes language after required checks pass} {--json : Print JSON only}';

    protected $description = 'Print a pull request body that links the issue, acceptance test, and Molly evidence';

    public function handle(ComposePullRequestBody $compose): int
    {
        try {
            $result = $compose->handle((string) $this->argument('task'), (bool) $this->option('close'));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->line($result['body']);
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
