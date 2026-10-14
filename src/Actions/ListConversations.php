<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Conversations\ConversationStore;
use Sifrious\Molly\Models\Task;

final class ListConversations
{
    public function __construct(
        private ConversationStore $store,
        private EnsureRunConversation $ensure,
    ) {}

    /**
     * @return array{conversations: list<array<string, mixed>>, path: string}
     */
    public function handle(?string $taskId = null, bool $ensure = false): array
    {
        if ($ensure) {
            $this->ensureMissing($taskId);
        }
        $items = $taskId === null || $taskId === ''
            ? $this->store->all()
            : $this->store->forTask($taskId);

        return [
            'path' => $this->store->root(),
            'conversations' => array_map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'workspace' => $c->workspace,
                'task_ids' => $c->taskIds,
                'run_ids' => $c->runIds,
                'message_count' => count($c->messages),
                'updated_at' => $c->updatedAt,
                'links' => $c->toArray()['links'],
            ], $items),
        ];
    }

    private function ensureMissing(?string $taskId): void
    {
        $tasks = Task::query()->with('runs')->orderBy('id')->get();
        if (is_string($taskId) && $taskId !== '') {
            $tasks = $tasks->filter(fn (Task $task): bool => $task->id === $taskId || $task->nickname === $taskId);
        }
        foreach ($tasks as $task) {
            foreach ($task->runs as $run) {
                if ($this->store->findForRun($run->id) === null) {
                    $this->ensure->handle($run);
                }
            }
        }
    }
}
