<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\HandOffTask;
use Throwable;

use function Laravel\Prompts\note;

class MollyHandoffCommand extends Command
{
    use ReportsFailures;

    protected $signature = 'molly:handoff {task : Saved task name or ID} {--from= : Sender Bloom workspace UUID} {--to= : Recipient Bloom workspace UUID} {--action=implement : Requested next action} {--context= : Bounded context for the recipient} {--approve : Confirm handing the task to the recipient workspace} {--json : Print JSON only}';

    protected $description = 'Save and print a handoff envelope for a child Bloom workspace without widening scope';

    public function handle(HandOffTask $handoff): int
    {
        try {
            ['envelope' => $envelope, 'path' => $path] = $handoff->handle(
                (string) $this->argument('task'),
                (bool) $this->option('approve'),
                (string) $this->option('from'),
                (string) $this->option('to'),
                (string) $this->option('action'),
                (string) ($this->option('context') ?: 'Implement the locked acceptance test without changing protected files.'),
            );
            $payload = $envelope->toArray();
            if ($this->option('json')) {
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                note('Handoff '.$envelope->handoffId.' to '.$envelope->recipientWorkspaceId.'.');
                note('Saved handoff envelope: '.$path);
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['status' => 'error', 'error' => $exception->getMessage()]);
        }
    }
}
