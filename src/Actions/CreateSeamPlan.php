<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\DB;
use Sifrious\Molly\Models\Plan;
use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Seams\InstructionPacks;
use Sifrious\Molly\Seams\PackFiles;
use Sifrious\Molly\Seams\SeamError;
use Sifrious\Molly\Seams\SeamSteps;
use Sifrious\Molly\Seams\WorkspaceEvidence;
use Sifrious\Molly\Workspace;

final class CreateSeamPlan
{
    public function __construct(private InstructionPacks $packs, private PreviewSeamTests $preview,
        private PackFiles $files, private WorkspaceEvidence $identity, private SeamSteps $steps, private DecideRunCompletion $completion) {}

    public function handle(string $planId, string $workspace, string $seam, array $contract): SeamRevision
    {
        $plan = Plan::find($planId);
        if ($plan === null || ! $plan->completed()) {
            throw new SeamError('PLAN_INCOMPLETE', 'Finish the saved Molly planning review before adding an executable seam revision.', $planId);
        }
        $workspace = (new Workspace($workspace))->path;
        $pack = $this->packs->inspect($workspace, $seam);
        $preview = $this->preview->fromPack($workspace, $pack, $contract);
        $this->steps->validate($pack['plan']['steps']);
        $baseline = $this->identity->capture($workspace, [$preview['path']]);
        $snapshot = ['workspace' => $workspace, 'pack' => $pack, 'preview' => $preview,
            'baseline' => $baseline, 'planning' => ['answers' => $plan->answers, 'guide_version' => $plan->guide_version, 'review_mode' => $plan->review_mode],
            'policies' => $this->completion->seamPolicies($pack['plan']['steps']), 'capabilities' => $pack['manifest']['capabilities']];
        $digest = hash('sha256', $this->files->canonical($snapshot));

        return (new Workspace($workspace))->exclusively(fn (): SeamRevision => DB::transaction(function () use ($plan, $seam, $snapshot, $digest): SeamRevision {
            Plan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $revisions = SeamRevision::where('plan_id', $plan->id)->where('seam_id', $seam)->orderByDesc('number')->get();
            $existing = $revisions->firstWhere('digest', $digest);
            if ($existing === null) {
                $existing = $revisions->first(function (SeamRevision $revision) use ($snapshot): bool {
                    $saved = $revision->snapshot;
                    $incoming = $snapshot;
                    // Writing this exact generated test is not a new behavior revision.
                    foreach (['before_digest', 'change'] as $key) {
                        unset($saved['preview'][$key], $incoming['preview'][$key]);
                    }

                    return $saved === $incoming;
                });
            }
            if ($existing !== null && $existing->status !== 'superseded') {
                return $existing;
            }
            foreach ($revisions as $revision) {
                if ($revision->active_run_id !== null || $revision->task?->status === 'running') {
                    throw new SeamError('RUN_ALREADY_ACTIVE', 'Finish or cancel the active attempt before creating a revision.', $revision->id);
                }
            }
            if ($existing !== null) {
                throw new SeamError('PLAN_REVISION_STALE', 'This snapshot belongs to a superseded revision. Use a new saved plan to repeat it.', $existing->id);
            }
            foreach ($revisions as $revision) {
                if (! in_array($revision->status, ['superseded', 'cancelled'], true)) {
                    $revision->update(['status' => 'superseded']);
                }
            }

            return SeamRevision::create(['plan_id' => $plan->id, 'seam_id' => $seam, 'number' => ($revisions->first()?->number ?? 0) + 1,
                'digest' => $digest, 'snapshot' => $snapshot, 'status' => 'preview', 'cursor' => 0, 'results' => []])->fresh();
        }));
    }
}
