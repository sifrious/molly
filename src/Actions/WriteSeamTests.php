<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\DB;
use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Seams\SeamError;
use Sifrious\Molly\Workspace;

final class WriteSeamTests
{
    public function __construct(private GuardSeamRevision $guard, private CreateTaskFromPlan $tasks) {}

    public function handle(string $id, string $reviewedDigest): SeamRevision
    {
        $revision = SeamRevision::find($id) ?? throw new SeamError('PLAN_REVISION_STALE', 'No saved seam revision has this ID.', $id);
        $workspace = new Workspace($revision->snapshot['workspace']);

        return $workspace->exclusively(function () use ($revision, $workspace, $reviewedDigest): SeamRevision {
            $revision->refresh();
            if (! hash_equals($revision->digest, $reviewedDigest)) {
                throw new SeamError('PLAN_REVISION_STALE', 'Review the current generation preview before authorizing its write.', $revision->id, $revision->digest, $reviewedDigest);
            }
            $candidate = $this->guard->handle($revision, requireTest: $revision->task_id !== null);
            if ($revision->task_id !== null) {
                return $revision;
            }
            if ($candidate !== $revision->snapshot['baseline']) {
                throw new SeamError('STALE_EVIDENCE', 'The baseline changed after preview. Create a new revision.', $revision->id);
            }
            $preview = $revision->snapshot['preview'];
            $before = $workspace->read([$preview['path']]);
            if ($before[$preview['path']] !== null && $before[$preview['path']] !== $preview['contents']) {
                throw new SeamError('OUTPUT_CONFLICT', 'Generation never overwrites a different user test. Choose a new test path.', $preview['path']);
            }
            $workspace->apply([['path' => $preview['path'], 'content' => $preview['contents']]], $before);
            try {
                DB::transaction(function () use ($revision, $workspace, $preview): void {
                    $task = $this->tasks->handle($revision->plan_id, 'Implement the reviewed seam contract '.$revision->seam_id.'. Revision '.$revision->id.'.',
                        $workspace->path, $preview['contract']['production_paths'], $preview['path'], allowTestEdits: true, requireRedBaseline: ($preview['contract']['verification_path'] ?? 'baseline_red') !== 'already_implemented');
                    $task->update(['source' => [...($task->source ?? []), 'seam_revision_id' => $revision->id, 'seam_revision_digest' => $revision->digest]]);
                    $revision->update(['task_id' => $task->id, 'status' => 'ready']);
                });
            } catch (\Throwable $exception) {
                if ($before[$preview['path']] === null && $workspace->testDigest($preview['path']) === $preview['sha256']) {
                    unlink($workspace->path.'/'.$preview['path']);
                }
                throw $exception;
            }

            return $revision->fresh();
        });
    }
}
