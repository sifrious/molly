<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Workspace;

/**
 * Read-only view of readiness, open tasks, the owned worker, and effective configuration.
 */
class ShowRuntimeStatus
{
    public function __construct(
        private CheckEnvironment $check,
        private ManageWorker $worker,
        private CheckGraphFreshness $graphs,
    ) {}

    /** @return array<string, mixed> */
    public function handle(?string $workspace): array
    {
        $root = (new Workspace($workspace ?? base_path()))->path;
        $now = Carbon::now();
        $tasks = Task::query()
            ->whereIn('status', ['pending', 'running'])
            ->when($workspace !== null, fn ($query) => $query->where('workspace', $root))
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'nickname' => $task->nickname,
                'status' => $task->status,
                'workspace' => $task->workspace,
                'worker_id' => $task->worker_id,
                'claimed_at' => $task->claimed_at?->toISOString(),
                'heartbeat_at' => $task->heartbeat_at?->toISOString(),
                'lease_expires_at' => $task->lease_expires_at?->toISOString(),
                'lease_expired' => $task->lease_expires_at === null ? null : $task->lease_expires_at->lt($now),
            ])->all();

        $connection = (string) config('queue.default');

        return [
            'workspace' => $root,
            'checked_at' => $now->toISOString(),
            'readiness' => $this->check->handle($root),
            'tasks' => [
                'scope' => $workspace === null ? 'all_workspaces' : 'workspace',
                'running' => array_values(array_filter($tasks, fn (array $task): bool => $task['status'] === 'running')),
                'pending' => array_values(array_filter($tasks, fn (array $task): bool => $task['status'] === 'pending')),
            ],
            'worker' => $this->worker->status($root),
            'graphs' => $this->graphs->handle($root),
            'config' => [
                'molly' => Arr::only((array) config('molly'), [
                    'agent', 'model', 'timeout', 'memory', 'test_timeout', 'max_files', 'max_file_bytes', 'max_attempts', 'agent_bus',
                    'parallel_checks', 'verification', 'verification_actions', 'false_green', 'sandbox', 'knowledge', 'preview', 'ui',
                ]) + ['jev' => Arr::only((array) config('molly.jev'), ['enabled', 'model', 'confidence_threshold', 'timeout'])]
                    + ['worker' => ['php_binary' => config('molly.worker.php_binary') ?: PHP_BINARY]],
                'queue' => [
                    'connection' => $connection,
                    'driver' => config('queue.connections.'.$connection.'.driver'),
                    'queue' => config('queue.connections.'.$connection.'.queue') ?? 'default',
                    'retry_after' => config('queue.connections.'.$connection.'.retry_after'),
                ],
            ],
        ];
    }
}
