<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Str;
use Sifrious\Molly\Conversations\Conversation;
use Sifrious\Molly\Conversations\ConversationStore;
use Sifrious\Molly\Models\Run;

/**
 * Build or refresh a persisted conversation from a run's prompt + report events.
 */
final class EnsureRunConversation
{
    public function __construct(private ConversationStore $store) {}

    public function handle(Run $run): Conversation
    {
        $run->loadMissing('task');
        $existing = $this->store->findForRun($run->id);
        $now = now()->toIso8601String();
        $messages = $this->messagesFromRun($run);
        $taskIds = array_values(array_filter([$run->task_id]));
        $title = $run->task?->nickname
            ? 'Run for '.$run->task->nickname
            : 'Run '.substr($run->id, 0, 8);

        if ($existing !== null) {
            $conversation = new Conversation(
                id: $existing->id,
                title: $title,
                messages: $messages,
                taskIds: array_values(array_unique([...$existing->taskIds, ...$taskIds])),
                runIds: array_values(array_unique([...$existing->runIds, $run->id])),
                createdAt: $existing->createdAt,
                updatedAt: $now,
                workspace: $run->workspace ?? $existing->workspace,
            );
        } else {
            $conversation = new Conversation(
                id: $this->store->makeId(),
                title: $title,
                messages: $messages,
                taskIds: $taskIds,
                runIds: [$run->id],
                createdAt: $now,
                updatedAt: $now,
                workspace: $run->workspace,
            );
        }

        $saved = $this->store->put($conversation);

        $report = is_array($run->report) ? $run->report : [];
        $report['conversation_id'] = $saved->id;
        $run->forceFill(['report' => $report])->save();

        return $saved;
    }

    /** @return list<array{id: string, sequence: int, role: string, body: string, at: string, provenance: array{source: string, kind: string, ref?: string}}> */
    private function messagesFromRun(Run $run): array
    {
        $report = is_array($run->report) ? $run->report : [];
        $at = optional($run->created_at)?->toIso8601String() ?? now()->toIso8601String();
        $messages = [];
        $seq = 0;

        $push = function (string $role, string $body, string $kind, ?string $ref = null) use (&$messages, &$seq, $at): void {
            $body = trim($body);
            if ($body === '') {
                return;
            }
            $provenance = ['source' => 'run', 'kind' => $kind];
            if ($ref !== null) {
                $provenance['ref'] = $ref;
            }
            $messages[] = [
                'id' => (string) Str::uuid(),
                'sequence' => $seq++,
                'role' => $role,
                'body' => $body,
                'at' => $at,
                'provenance' => $provenance,
            ];
        };

        $push('user', (string) $run->prompt, 'prompt', $run->id);

        if (is_string($report['phase'] ?? null)) {
            $push('system', 'Phase: '.$report['phase'], 'phase', $run->id);
        }
        if (is_string($report['summary'] ?? null)) {
            $push('assistant', $report['summary'], 'summary', $run->id);
        }
        if (is_array($report['verification'] ?? null)) {
            $status = $report['verification']['status'] ?? 'unknown';
            $push('system', 'Verification: '.(is_string($status) ? $status : json_encode($status)), 'verification', $run->id);
        }
        if (is_array($report['verification_receipts'] ?? null) && $report['verification_receipts'] !== []) {
            $push('system', 'Verification receipts recorded for this run.', 'verification_receipts', $run->id);
        }
        if (is_string($report['error'] ?? null)) {
            $push('system', 'Error: '.$report['error'], 'error', $run->id);
        }
        if (is_string($report['stop_reason'] ?? null)) {
            $push('system', 'Stop reason: '.$report['stop_reason'], 'stop_reason', $run->id);
        }

        // Linked Amp thread as provenance-only breadcrumb
        if (is_string($report['thread_id'] ?? null)) {
            $push('system', 'Linked Amp thread '.$report['thread_id'], 'amp_thread', $report['thread_id']);
        }

        return $messages;
    }
}
