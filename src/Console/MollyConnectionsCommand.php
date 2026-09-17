<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\FindTaskConnections;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

class MollyConnectionsCommand extends Command
{
    protected $signature = 'molly:connections {task : Saved task name or ID} {--stored : Read association history without contacting Amp} {--json : Print JSON only}';

    protected $description = 'Find Amp executors linked to a task, including prior associations';

    public function handle(FindTaskConnections $find): int
    {
        try {
            $result = $find->handle((string) $this->argument('task'), ! $this->option('stored'));
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                note($result['reason'] ?? 'Amp connection status observed.');
                if ($result['matches'] !== []) {
                    table(['Thread', 'Connection', 'Task association', 'Linked at'], array_map(fn (array $match): array => [
                        $match['thread_id'], $match['connection'],
                        $match['association'] === 'latest_recorded' ? 'Latest recorded task' : 'Prior task, now linked to '.$match['latest_task']['reference'],
                        $match['linked_at'],
                    ], $result['matches']));
                }
                note('Links are user-recorded history. Amp status does not verify an Orb identity or permission to run work.');
                if ($result['truncated']) {
                    note('Showing the 20 most recently linked threads.');
                }
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
