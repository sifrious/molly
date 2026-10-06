<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Models\Task;
use Sifrious\Molly\Seams\InstructionPacks;
use Sifrious\Molly\Seams\PackFiles;
use Sifrious\Molly\Seams\SeamError;
use Sifrious\Molly\Seams\WorkspaceEvidence;
use Sifrious\Molly\Workspace;

final class GuardSeamRevision
{
    public function __construct(private InstructionPacks $packs, private PackFiles $files, private WorkspaceEvidence $identity) {}

    public function handle(SeamRevision $revision, bool $requireTest = true): array
    {
        if (in_array($revision->status, ['superseded', 'cancelled'], true)) {
            throw new SeamError('PLAN_REVISION_STALE', 'This revision no longer permits execution. Its evidence remains readable.', $revision->id);
        }
        $snapshot = $revision->snapshot;
        if (! hash_equals($revision->digest, hash('sha256', $this->files->canonical($snapshot)))) {
            throw new SeamError('ARTIFACT_DIGEST_MISMATCH', 'The saved instruction snapshot changed.', $revision->id);
        }
        $pack = $this->packs->inspect($snapshot['workspace'], $revision->seam_id);
        if ($pack['digest'] !== $snapshot['pack']['digest']) {
            throw new SeamError('INSTRUCTION_DIGEST_CHANGED', 'The current pack differs from the frozen instructions. Create a new revision.', $pack['source_path'], $snapshot['pack']['digest'], $pack['digest']);
        }
        $planning = ['answers' => $revision->plan->answers, 'guide_version' => $revision->plan->guide_version, 'review_mode' => $revision->plan->review_mode];
        if ($planning !== $snapshot['planning']) {
            throw new SeamError('PLAN_REVISION_STALE', 'The saved planning decisions changed. Create a new revision.', $revision->plan_id);
        }
        if ($requireTest) {
            $actual = (new Workspace($snapshot['workspace']))->testDigest($snapshot['preview']['path']);
            if ($actual !== $snapshot['preview']['sha256']) {
                throw new SeamError('TEST_DIGEST_CHANGED', 'The generated acceptance test changed or is missing.', $snapshot['preview']['path'], $snapshot['preview']['sha256'], $actual);
            }
        }
        $candidate = $this->identity->capture($snapshot['workspace'], [$snapshot['preview']['path']]);
        $this->identity->assertScope($snapshot['baseline'], $candidate, $snapshot['preview']['contract']['production_paths']);
        if (($revision->results['post']['state'] ?? null) === 'PASS'
            && ($revision->results['post']['observed']['candidate']['tree_digest'] ?? null) !== $candidate['tree_digest']) {
            throw new SeamError('STALE_EVIDENCE', 'The candidate changed after POST. Create a new revision before reusing any evidence.', $revision->id);
        }

        return $candidate;
    }

    public function beforeLock(Task $task): void
    {
        $id = $task->source['seam_revision_id'] ?? null;
        if ($id === null) {
            return;
        }
        $revision = SeamRevision::find($id) ?? throw new SeamError('PLAN_REVISION_STALE', 'The task revision is missing.', $id);
        $candidate = $this->handle($revision);
        if (($revision->results['pre']['state'] ?? null) !== 'PASS'
            || $candidate['tree_digest'] !== $revision->snapshot['baseline']['tree_digest']) {
            throw new SeamError('PLAN_STEP_NOT_ALLOWED', 'Prove meaningful RED on the unchanged baseline or the declared already-implemented path before locking the tests.', $id);
        }
    }

    public function beforeImplementation(Task $task): void
    {
        $id = $task->source['seam_revision_id'] ?? null;
        if ($id === null) {
            return;
        }
        $revision = SeamRevision::find($id) ?? throw new SeamError('PLAN_REVISION_STALE', 'The task revision is missing.', $id);
        $this->handle($revision);
        if (($revision->results['lock']['state'] ?? null) !== 'PASS' || $task->allow_test_edits) {
            throw new SeamError('PLAN_STEP_NOT_ALLOWED', 'Complete PRE and the authorized lock before implementing the seam.', $id);
        }
    }
}
