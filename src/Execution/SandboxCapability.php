<?php

namespace Sifrious\Molly\Execution;

use DateTimeImmutable;
use DateTimeZone;
use Sifrious\Molly\Contracts\ExecutionTargetKind;
use Sifrious\Molly\Contracts\ExecutionTargetSnapshot;

final class SandboxCapability
{
    public function __construct(private Sandbox $sandbox) {}

    public function available(): bool
    {
        return $this->sandbox->available();
    }

    public function snapshot(): ExecutionTargetSnapshot
    {
        $available = $this->available();

        return new ExecutionTargetSnapshot(
            ExecutionTargetKind::Local,
            'local',
            $available ? ['sandbox_profile', 'workspace_mapping'] : ['workspace_mapping'],
            $available,
            'local',
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            $available
                ? 'This host isolates writer and verifier processes with Landlock and a network namespace.'
                : 'This host cannot supply a writer and verifier sandbox.',
        );
    }

    public function refuseSafeWorkflow(): void
    {
        $this->sandbox->refuseSafeWorkflow();
    }
}
