<?php

namespace Sifrious\Molly\Console;

use Illuminate\Console\Command;
use Sifrious\Molly\Actions\ShowRuntimeStatus;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

class MollyStatusCommand extends Command
{
    protected $signature = 'molly:status {--workspace= : Limit tasks to this workspace and read its worker record} {--json : Print JSON only}';

    protected $description = 'Show readiness, open tasks, the Molly queue worker, and effective settings without changing anything';

    public function handle(ShowRuntimeStatus $status): int
    {
        try {
            $result = $status->handle($this->option('workspace') ?: null);
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line(json_encode(['status' => 'error', 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));
            } else {
                error($exception->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['status' => 'ok', ...$result], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        table(['Check', 'Status', 'Code'], array_map(fn (array $row): array => [$row['name'], $row['status'], $row['code']], $result['readiness']['checks']));
        note($result['readiness']['ready'] ? 'Molly is ready.' : 'Some checks failed. Run php artisan molly:doctor for details.');

        $tasks = [...$result['tasks']['running'], ...$result['tasks']['pending']];
        if ($tasks === []) {
            note('No running or pending tasks.');
        } else {
            table(['Task', 'Status', 'Worker', 'Lease expires'], array_map(fn (array $task): array => [
                $task['nickname'] ?? $task['id'], $task['status'], $task['worker_id'] ?? 'none',
                $task['lease_expires_at'] === null ? 'none' : $task['lease_expires_at'].($task['lease_expired'] ? ' (expired)' : ''),
            ], $tasks));
        }

        $graphs = $result['graphs'];
        note(match ($graphs['status']) {
            'fresh' => 'Knowledge graphs match the checkout.',
            'stale' => 'Knowledge graphs are stale ('.implode(', ', $graphs['reasons']).'). Run '.$graphs['fix'].'.',
            'missing' => 'No knowledge graphs are recorded for this workspace. Run '.$graphs['fix'].'.',
            default => 'The graph manifest could not be read: '.($graphs['error'] ?? 'unknown error').'.',
        });

        $worker = $result['worker'];
        note('Worker: '.$worker['state'].($worker['pid'] === null ? '' : ', pid '.$worker['pid']).', queue '.$worker['connection'].' / '.$worker['queue'].'.');

        return self::SUCCESS;
    }
}
