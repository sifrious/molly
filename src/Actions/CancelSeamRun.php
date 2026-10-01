<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Models\SeamRevision;
use Sifrious\Molly\Seams\SeamError;

final class CancelSeamRun
{
    public function handle(string $id): array
    {
        $revision = SeamRevision::find($id) ?? throw new SeamError('PLAN_REVISION_STALE', 'No saved seam revision has this ID.', $id);
        if (! in_array($revision->status, ['completed', 'cancelled', 'superseded'], true)) {
            SeamRevision::whereKey($id)->whereNotIn('status', ['completed', 'cancelled', 'superseded'])
                ->whereNull('active_run_id')->update(['status' => 'cancelled']);
            SeamRevision::whereKey($id)->whereNotIn('status', ['completed', 'cancelled', 'superseded'])
                ->whereNotNull('active_run_id')->update(['status' => 'cancelling']);
            $revision->refresh();
        }

        return ['revision_id' => $id, 'status' => $revision->status, 'active_run_id' => $revision->active_run_id,
            'cleanup' => $revision->active_run_id === null ? 'No step is running. Evidence and generated tests are preserved.' : 'The bounded check will finish or time out, clean up, and record cancellation. No later step will run.'];
    }
}
