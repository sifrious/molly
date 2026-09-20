<?php

namespace Sifrious\Molly\Conversations;

use Illuminate\Support\Str;
use RuntimeException;
use Sifrious\Molly\Projects\ProjectRegistry;

final class ConversationStore
{
    public function root(): string
    {
        return (new ProjectRegistry)->home().'/conversations';
    }

    public function path(string $id): string
    {
        return $this->root().'/'.$id.'.json';
    }

    public function makeId(): string
    {
        return (string) Str::uuid();
    }

    /** @return list<Conversation> */
    public function all(): array
    {
        $dir = $this->root();
        if (! is_dir($dir)) {
            return [];
        }

        $conversations = [];
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $decoded = $this->readFile($file);
            if ($decoded !== null) {
                $conversations[] = $decoded;
            }
        }

        usort($conversations, fn (Conversation $a, Conversation $b): int => strcmp($b->updatedAt, $a->updatedAt));

        return $conversations;
    }

    public function find(string $id): ?Conversation
    {
        return $this->readFile($this->path($id));
    }

    public function findForRun(string $runId): ?Conversation
    {
        foreach ($this->all() as $conversation) {
            if (in_array($runId, $conversation->runIds, true)) {
                return $conversation;
            }
        }

        return null;
    }

    /** @return list<Conversation> */
    public function forTask(string $taskId): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (Conversation $conversation): bool => in_array($taskId, $conversation->taskIds, true),
        ));
    }

    public function put(Conversation $conversation): Conversation
    {
        $dir = $this->root();
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('CONVERSATION_STORE: Could not create '.$dir);
        }

        $path = $this->path($conversation->id);
        $json = json_encode($conversation->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($path, $json."\n") === false) {
            throw new RuntimeException('CONVERSATION_STORE: Could not write '.$path);
        }

        return $conversation;
    }

    private function readFile(string $path): ?Conversation
    {
        if (! is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $messages = [];
        foreach ($data['messages'] ?? [] as $index => $message) {
            if (! is_array($message) || ! is_string($message['body'] ?? null)) {
                continue;
            }
            $messages[] = [
                'id' => is_string($message['id'] ?? null) ? $message['id'] : 'm'.$index,
                'sequence' => is_int($message['sequence'] ?? null) ? $message['sequence'] : $index,
                'role' => is_string($message['role'] ?? null) ? $message['role'] : 'system',
                'body' => $message['body'],
                'at' => is_string($message['at'] ?? null) ? $message['at'] : ($data['updated_at'] ?? now()->toIso8601String()),
                'provenance' => is_array($message['provenance'] ?? null) ? $message['provenance'] : ['source' => 'molly', 'kind' => 'unknown'],
            ];
        }
        usort($messages, fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

        return new Conversation(
            id: (string) ($data['id'] ?? basename($path, '.json')),
            title: is_string($data['title'] ?? null) ? $data['title'] : 'Conversation',
            messages: $messages,
            taskIds: array_values(array_filter($data['task_ids'] ?? [], 'is_string')),
            runIds: array_values(array_filter($data['run_ids'] ?? [], 'is_string')),
            createdAt: is_string($data['created_at'] ?? null) ? $data['created_at'] : now()->toIso8601String(),
            updatedAt: is_string($data['updated_at'] ?? null) ? $data['updated_at'] : now()->toIso8601String(),
            workspace: is_string($data['workspace'] ?? null) ? $data['workspace'] : null,
        );
    }
}
