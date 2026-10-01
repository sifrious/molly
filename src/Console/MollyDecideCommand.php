<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\RecordProjectDecision;
use Throwable;

use function Laravel\Prompts\note;

class MollyDecideCommand extends Command
{
    use ReportsFailures;

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
                $this->writeJson($result);
            } elseif ($result['created']) {
                note('Decision saved: '.$result['path'].'. Commit that file if the project should keep it.');
            } else {
                note('Decision already recorded: '.$result['path']);
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            return $this->reportFailure($exception->getMessage(), ['status' => 'error', 'error' => $exception->getMessage()]);
        }
    }
}
