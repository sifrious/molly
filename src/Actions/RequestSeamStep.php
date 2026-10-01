<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\DB;
use Sifrious\Molly\Contracts\LifecycleEventType;
use Sifrious\Molly\Jobs\RunSeamStep;
use Sifrious\Molly\Models\Run;
use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Seams\SeamError;
use Sifrious\Molly\Workspace;

final class RequestSeamStep
{
    public function __construct(private GuardSeamRevision $guard, private RecordSeamEvent $events,
        private RecordSeamAttempt $evidence, private QueueTask $queue) {}

    public function handle(string $id, string $digest, string $stepId, string $requestKey, string $actor = 'coordinator', bool $queued = true): Run
    {
        if (! preg_match('/\A[a-zA-Z0-9:_-]{1,100}\z/D', $requestKey)
            || ! in_array($actor, ['author', 'implementer', 'verifier', 'coordinator'], true)) {
            throw new SeamError('INPUT_INVALID', 'Provide a bounded request key and a known actor role.', $id);
        }
        if ($queued) {
            $this->queue->assertAvailable();
        }
        $revision = SeamRevision::find($id) ?? throw new SeamError('PLAN_REVISION_STALE', 'No saved seam revision has this ID.', $id);
        $workspace = new Workspace($revision->snapshot['workspace']);
        $attempt = $workspace->exclusively(fn (): Run => DB::transaction(function () use ($revision, $digest, $stepId, $requestKey, $actor): Run {
            $revision->refresh();
            if ($revision->task_id === null || ! hash_equals($revision->digest, $digest)) {
                throw new SeamError('PLAN_STEP_NOT_ALLOWED', 'Write the reviewed tests before advancing this exact revision.', $revision->id);
            }
            $existing = Run::where('task_id', $revision->task_id)->where('report->seam->request_key', $requestKey)->first();
            if ($existing !== null) {
                if (($existing->report['seam']['step_id'] ?? null) !== $stepId) {
                    throw new SeamError('INPUT_INVALID', 'A request key cannot identify two different transitions.', $requestKey);
                }

                return $existing;
            }
            $this->guard->handle($revision);
            if ($revision->active_run_id !== null) {
                throw new SeamError('RUN_ALREADY_ACTIVE', 'Reconnect to the current run instead of starting another transition.', $revision->active_run_id);
            }
            $step = $revision->snapshot['pack']['plan']['steps'][$revision->cursor] ?? null;
            if ($step === null || $step['id'] !== $stepId || $revision->status === 'cancelling') {
                throw new SeamError('PLAN_STEP_NOT_ALLOWED', 'Only the next permitted step may run.', $revision->id, $step['id'] ?? null, $stepId);
            }
            foreach ($revision->results as $result) {
                if (is_array($result) && ($result['state'] ?? null) === 'PASS') {
                    $this->evidence->verify($result['artifacts'] ?? [], $revision->snapshot['workspace']);
                }
            }
            foreach ($step['after'] as $previous) {
                if (($revision->results[$previous]['state'] ?? null) !== 'PASS') {
                    throw new SeamError('PLAN_STEP_NOT_ALLOWED', 'The required earlier step has not passed.', $previous);
                }
            }
            $previous = Run::where('task_id', $revision->task_id)->where('report->seam->step_id', $stepId)->latest()->first();
            $attempt = Run::create(['task_id' => $revision->task_id, 'workspace' => $revision->snapshot['workspace'],
                'prompt' => 'Verify seam '.$revision->seam_id.' step '.$stepId.'.', 'status' => 'queued', 'report' => ['seam' => [
                    'schema_version' => 1, 'revision_id' => $revision->id, 'revision_digest' => $revision->digest,
                    'step_id' => $stepId, 'request_key' => $requestKey, 'actor_role' => $actor,
                    'correlation_id' => $revision->id, 'causation_id' => $previous?->id, 'previous_attempt_id' => $previous?->id,
                    'instruction_digest' => $revision->snapshot['pack']['digest'], 'contract_digest' => $revision->snapshot['preview']['contract_digest'],
                    'test_digest' => $revision->snapshot['preview']['sha256'],
                ]]]);
            $revision->update(['active_run_id' => $attempt->id]);
            $this->events->handle($revision, $attempt, LifecycleEventType::CommandRequested);

            return $attempt;
        }));
        if ($queued && $attempt->status === 'queued') {
            RunSeamStep::dispatch($attempt->id);
        }

        return $attempt;
    }
}
