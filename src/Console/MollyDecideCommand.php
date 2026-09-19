<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\RecordProjectDecision;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

class MollyDecideCommand extends Command
{
    protected $signature = 'molly:decide {--workspace= : Workspace path} {--title= : Short decision title} {--body= : The decision itself} {--task= : Optional saved task name or ID} {--json : Print JSON only}';

    protected $description = 'Record a Git-tracked architectural decision in docs/decisions';

    public function handle(RecordProjectDecision $record): int
    {
        try {
            $result = $record->handle(
                (string) ($this->option('workspace') ?: base_path()),
                (string) $this->option('title'),
                (string) $this->option('body'),
                $this->option('task'),
            );
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } elseif ($result['created']) {
                note('Decision saved: '.$result['path'].'. Commit that file if the project should keep it.');
            } else {
                note('Decision already recorded: '.$result['path']);
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
