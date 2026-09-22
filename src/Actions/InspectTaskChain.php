<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Conversations\ConversationStore;
use Sifrious\Molly\Models\Task;

/**
 * Bloom inspection payload: task → runs → conversations with bidirectional links.
 */
final class InspectTaskChain
{
    public function __construct(
        private ConversationStore $conversations,
        private EnsureRunConversation $ensureConversation,
        private ShowTask $show,
        private InspectTask $inspect,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $reference, bool $ensureConversations = true): array
    {
        $task = $this->show->handle($reference)
            ?? throw new RuntimeException('TASK_NOT_FOUND: No saved task has that name or ID.');

        $inspection = $this->inspect->handle($task);
        $runs = $task->runs->values();
        $latest = $runs->last();
        $conversationIds = [];

        $runSummaries = $runs->map(function ($run) use ($ensureConversations, &$conversationIds): array {
            $report = is_array($run->report) ? $run->report : [];
            $conversation = $this->conversations->findForRun($run->id);
            if ($conversation === null && $ensureConversations) {
                $conversation = $this->ensureConversation->handle($run);
            }
            if ($conversation !== null) {
                $conversationIds[] = $conversation->id;
            }

            return [
                'id' => $run->id,
                'status' => $run->status,
                'created_at' => optional($run->created_at)?->toIso8601String(),
                'phase' => $report['phase'] ?? null,
                'summary' => $report['summary'] ?? null,
                'conversation_id' => $conversation?->id,
                'effective_config' => $report['effective_config'] ?? $report['effective_configuration'] ?? null,
                'links' => [
                    'self' => 'molly.run:'.$run->id,
                    'conversation' => $conversation ? 'molly.conversation:'.$conversation->id : null,
                ],
            ];
        })->all();

        $conversationIds = array_values(array_unique($conversationIds));
        $latestReport = is_array($latest?->report) ? $latest->report : [];

        return [
            'task' => [
                'id' => $task->id,
                'nickname' => $task->nickname,
                'reference' => $task->reference(),
                'status' => $task->status,
                'display_status' => $inspection['display_status'],
                'prompt' => $task->prompt,
                'workspace' => $task->workspace,
                'paths' => $task->paths,
                'test_path' => $task->test_path,
                'stop_requested_at' => optional($task->stop_requested_at)?->toIso8601String(),
                'source' => $task->source,
                'created_at' => optional($task->created_at)?->toIso8601String(),
                'updated_at' => optional($task->updated_at)?->toIso8601String(),
            ],
            'project' => [
                'workspace' => $task->workspace,
            ],
            'latest_run_id' => $latest?->id,
            'loop' => [
                'phase' => $latestReport['phase'] ?? null,
                'mode' => $latestReport['mode'] ?? null,
                'status' => $latest?->status,
            ],
            'effective_config' => $latestReport['effective_config'] ?? $latestReport['effective_configuration'] ?? null,
            'failure_reason' => $latestReport['error'] ?? null,
            'linked_pr' => $inspection['linked_pr'],
            'issue_url' => $inspection['issue_url'],
            'runs' => $runSummaries,
            'conversation_ids' => $conversationIds,
            'actions' => [
                'retry' => 'molly:retry '.$task->reference(),
                'stop' => 'molly:stop '.$task->reference(),
            ],
            'links' => [
                'self' => 'molly.task:'.$task->id,
                'latest_run' => $latest ? 'molly.run:'.$latest->id : null,
                'conversations' => array_map(fn (string $id): string => 'molly.conversation:'.$id, $conversationIds),
            ],
        ];
    }
}
