<?php

namespace Sifrious\Molly\Conversations;

/**
 * Ordered Molly conversation with provenance and bidirectional task/run links.
 *
 * @phpstan-type Message array{
 *     id: string,
 *     sequence: int,
 *     role: string,
 *     body: string,
 *     at: string,
 *     provenance: array{source: string, kind: string, ref?: string}
 * }
 */
final class Conversation
{
    /**
     * @param  list<Message>  $messages
     * @param  list<string>  $taskIds
     * @param  list<string>  $runIds
     */
    public function __construct(
        public string $id,
        public string $title,
        public array $messages,
        public array $taskIds,
        public array $runIds,
        public string $createdAt,
        public string $updatedAt,
        public ?string $workspace = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'workspace' => $this->workspace,
            'task_ids' => array_values($this->taskIds),
            'run_ids' => array_values($this->runIds),
            'messages' => array_values($this->messages),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'links' => [
                'tasks' => array_map(fn (string $id): string => 'molly.task:'.$id, $this->taskIds),
                'runs' => array_map(fn (string $id): string => 'molly.run:'.$id, $this->runIds),
            ],
        ];
    }
}
