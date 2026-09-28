<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\LockProtectedTest;
use Throwable;

use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

class MollyLockTestCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:lock-test {task : Saved task name or ID} {--approve : Confirm the Pest file is the locked acceptance test} {--reason= : Why this digest is locked} {--file=* : Implementation file the next run may change, instead of the files derived from the story} {--json : Print JSON only}';

    protected $description = 'Lock the required Pest test after a test-authoring task and drop it from the writer scope';

    public function handle(LockProtectedTest $lock): int
    {
        try {
            $result = $lock->handle(
                (string) $this->argument('task'),
                (bool) $this->option('approve'),
                array_values(array_filter(array_map(trim(...), $this->option('file')))),
                (string) ($this->option('reason') ?: 'Human approved the Pest test as the locked acceptance test.'),
            );
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                note('Locked '.$result['test_path'].' at '.$result['after_digest'].'. The next run cannot edit that file.');
                table(['File the implementation may change'], array_map(fn (string $path): array => [$path], $result['paths']));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['status' => 'error', 'error' => $exception->getMessage()]);
        }
    }
}
