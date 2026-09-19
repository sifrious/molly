<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\LockProtectedTest;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyLockTestCommand extends Command
{
    protected $signature = 'molly:lock-test {task : Saved task name or ID} {--approve : Confirm the Pest file is the locked acceptance test} {--reason= : Why this digest is locked} {--file=* : Implementation files the next run may change} {--json : Print JSON only}';

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
