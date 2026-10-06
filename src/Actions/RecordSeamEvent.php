<?php

namespace Sifrious\Molly\Actions;

use Ramsey\Uuid\Uuid;
use Sifrious\Molly\Contracts\LifecycleEvent;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Seams\SeamError;
use Sifrious\Molly\Workspace\Directory;

final class RecordSeamEvent
{
    public function __construct(private RecordLifecycleEvent $events) {}

    public function handle(SeamRevision $revision, Run $attempt, LifecycleEventType $type, array $details = []): void
    {
        $workspace = $revision->snapshot['workspace'];
        $path = Directory::molly($workspace, 'lifecycle.jsonl');
        $sequence = 0;
        try {
            foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [] as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $event = LifecycleEvent::fromJson($line);
                if ($event->runId === $attempt->id) {
                    $sequence = max($sequence, (int) ($event->payload['sequence'] ?? 0));
                }
            }
            $this->events->handle($workspace, $type, $revision->task_id, $attempt->id, [
                'schema_version' => 1, 'sequence' => $sequence + 1, ...$attempt->report['seam'],
                'seam_id' => $revision->seam_id, 'plan_id' => $revision->plan_id, 'revision_id' => $revision->id,
                'instruction_digest' => $revision->snapshot['pack']['digest'], 'contract_digest' => $revision->snapshot['preview']['contract_digest'],
                'test_digest' => $revision->snapshot['preview']['sha256'], 'baseline_commit' => $revision->snapshot['baseline']['commit'],
                ...$details,
            ], Uuid::uuid5(Uuid::NAMESPACE_URL, $attempt->id.':'.$type->value)->toString());
        } catch (\Throwable $exception) {
            throw new SeamError('EVENT_PERSIST_FAILED', 'Could not append the verification event: '.$exception->getMessage(), $path);
        }
    }
}
