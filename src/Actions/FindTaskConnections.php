<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Sifrious\Molly\Models\Task;

class FindTaskConnections
{
    public function __construct(private ReadAmpConnections $connections) {}

    /** @return array<string, mixed> */
    public function handle(string $reference, bool $refresh = true): array
    {
        $task = Task::findByReference($reference)
            ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');
        $links = DB::table('molly_task_threads')->where('task_id', $task->id)
            ->whereIn('id', DB::table('molly_task_threads')->selectRaw('MAX(id)')->where('task_id', $task->id)->groupBy('thread_id'))
            ->orderByDesc('id')->limit(21)->get();
        $truncated = $links->count() > 20;
        $links = $links->take(20);
        $threadIds = $links->pluck('thread_id')->all();
        $observation = $refresh && $threadIds !== []
            ? $this->connections->handle($threadIds)
            : ['status' => 'not_checked', 'reason' => $threadIds === [] ? 'No Amp threads are linked to this task.' : 'Check connections to read current Amp status.',
                'provider_version' => null, 'observed_at' => null, 'threads' => []];
        $observed = collect($observation['threads'])->keyBy('thread_id');
        $latest = DB::table('molly_task_threads')->whereIn('thread_id', $threadIds)
            ->whereIn('id', DB::table('molly_task_threads')->selectRaw('MAX(id)')->whereIn('thread_id', $threadIds)->groupBy('thread_id'))
            ->get()->keyBy('thread_id');
        $tasks = Task::whereIn('id', $latest->pluck('task_id'))->get()->keyBy('id');
        $matches = $links->map(function (object $link) use ($latest, $tasks, $observed): array {
            $last = $latest[$link->thread_id];
            $connection = $observed->get($link->thread_id, []);
            $current = $last->task_id === $link->task_id;

            return [
                'thread_id' => $link->thread_id,
                'url' => 'https://ampcode.com/threads/'.$link->thread_id,
                'association' => $current ? 'latest_recorded' : 'prior_exact',
                'association_id' => $link->id,
                'linked_at' => $link->linked_at,
                'source' => 'user',
                'latest_task' => ['id' => $last->task_id, 'reference' => $tasks[$last->task_id]->reference()],
                'connection' => ($connection['status'] ?? null) === 'observed' && is_bool($connection['executor_connected'] ?? null)
                    ? ($connection['executor_connected'] ? 'connected' : 'disconnected') : 'unknown',
                'working' => ($connection['status'] ?? null) === 'observed' ? ($connection['working'] ?? null) : null,
                'executor_type' => $connection['executor_type'] ?? null,
                'reason' => $connection['reason'] ?? null,
            ];
        })->sort(fn (array $left, array $right): int => (($right['connection'] === 'connected') <=> ($left['connection'] === 'connected'))
            ?: (($right['association'] === 'latest_recorded') <=> ($left['association'] === 'latest_recorded'))
            ?: ($right['association_id'] <=> $left['association_id']))->values()->all();

        return [
            'task_id' => $task->id, 'task' => $task->reference(), 'status' => $observation['status'],
            'reason' => $observation['reason'], 'observed_at' => $observation['observed_at'],
            'provider_version' => $observation['provider_version'], 'matches' => $matches,
            'truncated' => $truncated, 'orb_identity' => 'unverified',
        ];
    }
}
