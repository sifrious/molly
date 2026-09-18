<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\HandOffTask;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyHandoffCommand extends Command
{
    protected $signature = 'molly:handoff {task : Saved task name or ID} {--from= : Sender Bloom workspace UUID} {--to= : Recipient Bloom workspace UUID} {--action=implement : Requested next action} {--context= : Bounded context for the recipient} {--json : Print JSON only}';

    protected $description = 'Print a handoff envelope for a child Bloom workspace without widening scope';

    public function handle(HandOffTask $handoff): int
    {
        try {
            $envelope = $handoff->handle(
                (string) $this->argument('task'),
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
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
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
