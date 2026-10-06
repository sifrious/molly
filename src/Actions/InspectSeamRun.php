<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Seams\PackFiles;
use Sifrious\Molly\Seams\SeamError;

final class InspectSeamRun
{
    public function __construct(private DecideRunCompletion $completion, private PackFiles $files, private GuardSeamRevision $guard, private RecordSeamAttempt $evidence) {}

    public function handle(string $id): array
    {
        $revision = SeamRevision::find($id) ?? throw new SeamError('PLAN_REVISION_STALE', 'No saved seam revision has this ID.', $id);
        $decision = $this->completion->forSeam($revision->snapshot['policies'], $revision->results);
        $step = $revision->snapshot['pack']['plan']['steps'][$revision->cursor] ?? null;
        $blockers = $decision['blockers'];
        $current = null;
        try {
            $current = $this->guard->handle($revision, requireTest: $revision->task_id !== null);
            foreach ($revision->results as $result) {
                if (($result['state'] ?? null) === 'PASS') {
                    $this->evidence->verify($result['artifacts'] ?? [], $revision->snapshot['workspace']);
                }
            }
        } catch (\Throwable $exception) {
            $current = null;
            $blockers[] = $exception instanceof SeamError ? $exception->errorCode : 'ENVIRONMENT_UNAVAILABLE';
        }
        $allowed = match (true) {
            in_array($revision->status, ['superseded', 'cancelled', 'cancelling'], true) => [],
            $revision->active_run_id !== null => ['status', 'cancel', 'resume'],
            $revision->task_id === null => ['preview', 'write', 'cancel'],
            $step === null => ['report'],
            $step['id'] === 'handoff' => ['acknowledge', 'advance', 'cancel'],
            default => ['advance', 'cancel'],
        };
        if ($current === null) {
            $allowed = ['report', 'revise'];
        }
        $result = ['revision_id' => $revision->id, 'plan_id' => $revision->plan_id, 'seam_id' => $revision->seam_id,
            'revision' => $revision->number, 'revision_digest' => $revision->digest, 'status' => $revision->status,
            'completed' => $revision->status === 'completed' && $step === null && $decision['completed'] && $blockers === [], 'task_id' => $revision->task_id,
            'active_run_id' => $revision->active_run_id, 'next_step' => $step, 'allowed_actions' => $allowed,
            'blockers' => $blockers, 'checks' => $decision['outcomes'], 'results' => $revision->results,
            'attempts' => $revision->task?->runs->map(fn ($run): array => ['id' => $run->id, 'status' => $run->status, 'report' => $run->report])->all() ?? [],
            'baseline' => $revision->snapshot['baseline'], 'candidate' => $current,
            'instruction_digest' => $revision->snapshot['pack']['digest'], 'test_digest' => $revision->snapshot['preview']['sha256']];
        if (($revision->results['cleanup']['state'] ?? null) === 'PASS' && $current !== null) {
            $evidence = array_intersect_key($revision->results, array_flip(array_filter(array_keys($revision->snapshot['policies']), fn (string $id): bool => $id !== 'handoff')));
            $result['handoff'] = ['schema_version' => 1, 'revision_id' => $revision->id, 'revision_digest' => $revision->digest,
                'candidate_digest' => $current['tree_digest'], 'evidence_digest' => hash('sha256', $this->files->canonical($evidence)),
                'next_action' => 'Verify the referenced artifacts and acknowledge these exact digests. This acknowledgement does not replace verification.'];
        }

        return $result;
    }
}
