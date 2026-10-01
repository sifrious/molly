<?php

namespace Sifrious\Molly\Execution;

use RuntimeException;
use Sifrious\Molly\Contracts\ExecutionTargetKind;
use Sifrious\Molly\Contracts\ExecutionTargetRequest;
use Sifrious\Molly\Contracts\ExecutionTargetSnapshot;
use Sifrious\Molly\Models\Task;

/**
 * The one place Molly decides where a task runs. With no request it selects this machine.
 * An Orb request goes to LocalOrbProvider, which chooses and reserves a registered local Orb
 * for the task. Hosted Orbs are not shipped; a hosted provider would join here.
 */
final class SelectExecutionTarget
{
    public function __construct(private SandboxCapability $sandbox, private LocalOrbProvider $orbs) {}

    public function handle(?ExecutionTargetRequest $request = null, ?Task $task = null): ExecutionTargetSnapshot
    {
        $request ??= ExecutionTargetRequest::local('Local execution is the default.');
        if ($request->kind === ExecutionTargetKind::Orb) {
            if ($task === null) {
                throw new RuntimeException('ORB_TASK_REQUIRED: An Orb runs a saved task. Save the task with php artisan molly:create, then place it on an Orb.');
            }

            return $this->orbs->place($request, $task);
        }

        $snapshot = $this->sandbox->snapshot();

        return new ExecutionTargetSnapshot(
            ExecutionTargetKind::Local,
            'local',
            $snapshot->capabilities,
            $snapshot->sandbox,
            $snapshot->provider,
            $snapshot->observedAt,
            $request->reason ?? 'Local execution is the cost-conscious default.',
        );
    }
}
