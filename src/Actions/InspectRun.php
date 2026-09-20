<?php

namespace Sifrious\Molly\Actions;

use RuntimeException;
use Sifrious\Molly\Conversations\ConversationStore;
use Sifrious\Molly\Models\Run;

final class InspectRun
{
    public function __construct(
        private ConversationStore $conversations = new ConversationStore,
        private EnsureRunConversation $ensureConversation = new EnsureRunConversation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(string $id, bool $ensureConversation = true): array
    {
        $run = Run::with('task')->find($id)
            ?? throw new RuntimeException('RUN_NOT_FOUND: No saved run has that ID.');

        $report = is_array($run->report) ? $run->report : [];
        $conversation = $this->conversations->findForRun($run->id);
        if ($conversation === null && $ensureConversation) {
            $conversation = $this->ensureConversation->handle($run);
            $run->refresh();
            $report = is_array($run->report) ? $run->report : [];
        }

        $effectiveConfig = $report['effective_config'] ?? $report['effective_configuration'] ?? null;

        return [
            'run' => [
                'id' => $run->id,
                'task_id' => $run->task_id,
                'status' => $run->status,
                'prompt' => $run->prompt,
                'workspace' => $run->workspace,
                'created_at' => optional($run->created_at)?->toIso8601String(),
                'updated_at' => optional($run->updated_at)?->toIso8601String(),
            ],
            'task' => $run->task ? [
                'id' => $run->task->id,
                'nickname' => $run->task->nickname,
                'status' => $run->task->status,
                'reference' => $run->task->reference(),
            ] : null,
            'loop' => [
                'phase' => $report['phase'] ?? null,
                'mode' => $report['mode'] ?? null,
                'agent' => $report['agent'] ?? ($effectiveConfig['runtime']['agent'] ?? null),
                'model' => $report['model'] ?? ($effectiveConfig['runtime']['model'] ?? null),
                'iterations' => $report['iterations'] ?? $report['iteration'] ?? null,
            ],
            'timing' => [
                'started_at' => $report['started_at'] ?? optional($run->created_at)?->toIso8601String(),
                'finished_at' => $report['finished_at'] ?? null,
                'duration_ms' => $report['duration_ms'] ?? null,
            ],
            'inputs' => [
                'prompt' => $run->prompt,
                'workspace' => $run->workspace,
            ],
            'outputs' => [
                'summary' => $report['summary'] ?? null,
                'changes' => $report['changes'] ?? [],
            ],
            'retries' => $report['retries'] ?? $report['retry_of'] ?? null,
            'stop_reason' => $report['stop_reason'] ?? ($run->status === 'stopped' ? 'stopped' : null),
            'verification' => $report['verification'] ?? null,
            'verification_receipts' => $report['verification_receipts'] ?? null,
            'evidence' => [
                'git' => $report['git'] ?? $report['snapshots'] ?? null,
                'components' => $report['components'] ?? null,
            ],
            'effective_config' => $effectiveConfig,
            'conversation_id' => $conversation?->id ?? ($report['conversation_id'] ?? null),
            'links' => [
                'task' => $run->task_id ? 'molly.task:'.$run->task_id : null,
                'conversation' => ($conversation?->id ?? null) ? 'molly.conversation:'.$conversation->id : null,
                'self' => 'molly.run:'.$run->id,
            ],
            'report' => $report,
        ];
    }
}
