<?php

namespace Sifrious\Molly\Execution;

use RuntimeException;
use Sifrious\Molly\Contracts\ExecutionTargetKind;
use Sifrious\Molly\Contracts\ExecutionTargetRequest;
use Sifrious\Molly\Contracts\ExecutionTargetSnapshot;

final class SelectExecutionTarget
{
    public function __construct(private SandboxCapability $sandbox) {}

    /**
     * Remote Orb execution is not shipped. Every Orb request is refused,
     * whatever target it names; Molly never dispatches remote work.
     */
    public function handle(?ExecutionTargetRequest $request = null): ExecutionTargetSnapshot
    {
        $request ??= ExecutionTargetRequest::local('Local execution is the default.');
        if ($request->kind === ExecutionTargetKind::Orb) {
            throw new RuntimeException('ORB_UNVERIFIED: This Molly release runs tasks only on the local machine. An Amp thread or connected executor is not a verified Orb. Select local execution.');
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
