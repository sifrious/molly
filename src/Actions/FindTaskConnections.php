<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
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
        $threadIds = $this->associations($task->id)->take(20)->pluck('thread_id')->all();
        $observation = $refresh && $threadIds !== []
            ? $this->connections->handle($threadIds)
            : ['status' => 'not_checked', 'reason' => $threadIds === [] ? 'No Amp threads are linked to this task.' : 'Check connections to read current Amp status.',
                'provider_version' => null, 'observed_at' => null, 'threads' => []];
        $observed = collect($observation['threads'])->keyBy('thread_id');
        $links = $this->associations($task->id);
        $matches = $links->take(20)->map(fn (object $link): array => $this->match($link, $observed->get($link->thread_id, [])))
            ->sort(fn (array $left, array $right): int => (($right['connection'] === 'connected') <=> ($left['connection'] === 'connected'))
            ?: (($right['association'] === 'latest_recorded') <=> ($left['association'] === 'latest_recorded'))
            ?: ($right['association_id'] <=> $left['association_id']))->values()->all();

        return [
            'task_id' => $task->id, 'task' => $task->reference(), 'status' => $observation['status'],
            'reason' => $observation['reason'], 'observed_at' => $observation['observed_at'],
            'provider_version' => $observation['provider_version'], 'matches' => $matches,
            'truncated' => $links->count() > 20, 'orb_identity' => 'unverified',
        ];
    }

    /** @return Collection<int, object> */
    private function associations(string $taskId): Collection
    {
        $taskLinks = DB::table('molly_task_threads')->selectRaw('MAX(id) AS id')->where('task_id', $taskId)->groupBy('thread_id');
        $latestLinks = DB::table('molly_task_threads')->selectRaw('thread_id, MAX(id) AS id')->groupBy('thread_id');

        return DB::table('molly_task_threads as association')
            ->joinSub($taskLinks, 'task_latest', fn (JoinClause $join): JoinClause => $join->on('association.id', '=', 'task_latest.id'))
            ->joinSub($latestLinks, 'thread_latest', fn (JoinClause $join): JoinClause => $join->on('association.thread_id', '=', 'thread_latest.thread_id'))
            ->join('molly_task_threads as current_link', 'current_link.id', '=', 'thread_latest.id')
            ->join('molly_tasks as latest_task', 'latest_task.id', '=', 'current_link.task_id')
            ->select('association.*', 'current_link.task_id as latest_task_id', 'latest_task.nickname as latest_nickname')
            ->orderByDesc('association.id')->limit(21)->get();
    }

    /** @param array<string, mixed> $connection
     * @return array<string, mixed>
     */
    private function match(object $link, array $connection): array
    {
        return [
            'thread_id' => $link->thread_id,
            'url' => 'https://ampcode.com/threads/'.$link->thread_id,
            'association' => $link->latest_task_id === $link->task_id ? 'latest_recorded' : 'prior_exact',
            'association_id' => $link->id,
            'linked_at' => $link->linked_at,
            'source' => 'user',
            'latest_task' => ['id' => $link->latest_task_id, 'reference' => $link->latest_nickname ?? $link->latest_task_id],
            'connection' => ($connection['status'] ?? null) === 'observed' && is_bool($connection['executor_connected'] ?? null)
                ? ($connection['executor_connected'] ? 'connected' : 'disconnected') : 'unknown',
            'working' => ($connection['status'] ?? null) === 'observed' ? ($connection['working'] ?? null) : null,
            'executor_type' => $connection['executor_type'] ?? null,
            'reason' => $connection['reason'] ?? null,
        ];
    }
}
