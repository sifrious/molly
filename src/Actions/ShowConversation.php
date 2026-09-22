<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Conversations\ConversationStore;

final class ShowConversation
{
    public function __construct(private ConversationStore $store) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $id): array
    {
        $conversation = $this->store->find($id)
            ?? throw new RuntimeException('CONVERSATION_NOT_FOUND: No conversation has that ID.');

        return $conversation->toArray();
    }
}
