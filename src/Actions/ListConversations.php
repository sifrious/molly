<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Conversations\ConversationStore;

final class ListConversations
{
    public function __construct(private ConversationStore $store = new ConversationStore) {}

    /**
     * @return array{conversations: list<array<string, mixed>>, path: string}
     */
    public function handle(?string $taskId = null): array
    {
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
}
