<?php

namespace Sifrious\Molly\Actions;

use Illuminate\Support\Facades\Validator;
use Sifrious\Molly\Jobs\RunSeamStep;
use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Seams\InstructionPacks;
use Sifrious\Molly\Seams\SeamError;

final class SeamWorkflow
{
    public const OPERATIONS = ['list', 'inspect', 'compare', 'validate', 'plan', 'preview', 'write', 'advance', 'status', 'report', 'cancel', 'acknowledge', 'resume'];

    public function __construct(private InstructionPacks $packs, private PreviewSeamTests $preview, private CreateSeamPlan $create,
        private WriteSeamTests $write, private RequestSeamStep $request, private InspectSeamRun $inspect,
        private CancelSeamRun $cancel, private AcknowledgeSeamHandoff $acknowledge) {}

    public function handle(array $input, bool $wait = false): array
    {
        $allowed = ['operation', 'workspace', 'seam', 'contract', 'plan', 'revision', 'digest', 'step', 'key', 'actor', 'candidate_digest', 'evidence_digest', 'recipient'];
        $validation = Validator::make($input, [
            'operation' => ['required', 'in:'.implode(',', self::OPERATIONS)],
            'workspace' => ['required_if:operation,list,inspect,compare,validate,plan', 'string'],
            'seam' => ['required_if:operation,inspect,compare,validate,plan', 'string', 'max:160'],
            'contract' => ['required_if:operation,validate,plan', 'array'],
            'plan' => ['required_if:operation,plan', 'uuid'],
            'revision' => ['required_if:operation,preview,write,advance,status,report,cancel,acknowledge,resume', 'uuid'],
            'digest' => ['required_if:operation,write,advance,acknowledge', 'regex:/\A[a-f0-9]{64}\z/'],
            'step' => ['required_if:operation,advance', 'string', 'max:100'],
            'key' => ['required_if:operation,advance', 'string', 'max:100'],
            'actor' => ['sometimes', 'in:author,implementer,verifier,coordinator'],
            'candidate_digest' => ['required_if:operation,acknowledge', 'regex:/\A[a-f0-9]{64}\z/'],
            'evidence_digest' => ['required_if:operation,acknowledge', 'regex:/\A[a-f0-9]{64}\z/'],
            'recipient' => ['required_if:operation,acknowledge', 'string', 'max:100'],
        ]);
        if ($validation->fails() || array_diff(array_keys($input), $allowed) !== []) {
            throw new SeamError('INPUT_INVALID', 'Supply the documented fields for this seam operation. Clients cannot submit verification results.', 'request', $allowed, $validation->errors()->toArray());
        }

        return match ($input['operation']) {
            'list' => ['packs' => $this->packs->list($input['workspace']), 'catalogue' => $this->packs->catalogue()],
            'inspect' => $this->packs->inspect($input['workspace'], $input['seam']),
            'compare' => $this->packs->compare($input['workspace'], $input['seam']),
            'validate' => ['valid' => true, 'preview' => $this->preview->handle($input['workspace'], $input['seam'], $input['contract'])],
            'plan' => $this->revision($this->create->handle($input['plan'], $input['workspace'], $input['seam'], $input['contract'])),
            'preview' => $this->revision(SeamRevision::find($input['revision']) ?? throw new SeamError('PLAN_REVISION_STALE', 'No saved seam revision has this ID.', $input['revision'])),
            'write' => $this->revision($this->write->handle($input['revision'], $input['digest'])),
            'advance' => $this->advance($input, $wait),
            'status', 'report' => $this->inspect->handle($input['revision']),
            'cancel' => $this->cancel->handle($input['revision']),
            'acknowledge' => $this->acknowledge->handle($input['revision'], $input['digest'], $input['candidate_digest'], $input['evidence_digest'], $input['recipient']),
            'resume' => $this->resume($input['revision'], $wait),
        };
    }

    private function revision(SeamRevision $revision): array
    {
        return [...$this->inspect->handle($revision->id), 'preview' => $revision->snapshot['preview'],
            'instructions' => $revision->snapshot['pack']['files']['instructions.md'],
            'steps' => $revision->snapshot['pack']['plan']['steps']];
    }

    private function advance(array $input, bool $wait): array
    {
        $run = $this->request->handle($input['revision'], $input['digest'], $input['step'], $input['key'], $input['actor'] ?? 'coordinator', ! $wait);
        if ($wait) {
            $run = app(ExecuteSeamStep::class)->handle($run->id);
        }

        return ['run_id' => $run->id, 'run_status' => $run->status, ...$this->inspect->handle($input['revision'])];
    }

    private function resume(string $id, bool $wait): array
    {
        $revision = SeamRevision::find($id) ?? throw new SeamError('PLAN_REVISION_STALE', 'No saved seam revision has this ID.', $id);
        if ($revision->active_run_id !== null) {
            if ($wait) {
                app(ExecuteSeamStep::class)->handle($revision->active_run_id);
            } else {
                app(QueueTask::class)->assertAvailable();
                RunSeamStep::dispatch($revision->active_run_id);
            }
        }

        return $this->inspect->handle($id);
    }
}
