<?php

namespace Sifrious\Molly\Tests\Support;

use Sifrious\Molly\Acceptance\MacOsPermissionPrompt;
use Sifrious\Molly\Acceptance\MacOsTccProbe;
use Sifrious\Molly\Acceptance\PermissionKind;
use Sifrious\Molly\Acceptance\PermissionState;
use Sifrious\Molly\Acceptance\VerifierPermissionSnapshot;

final class StubPermissionPrompt extends MacOsPermissionPrompt
{
    public bool $requested = false;

    /** @var list<string> */
    public array $opened = [];

    public function __construct()
    {
        parent::__construct(new MacOsTccProbe);
    }

    public function request(VerifierPermissionSnapshot $before): bool
    {
        $this->requested = true;

        return true;
    }

    /** @return list<string> */
    public function openSettings(VerifierPermissionSnapshot $after): array
    {
        $opened = [];
        foreach (PermissionKind::cases() as $kind) {
            $state = $after->state($kind);
            if ($state !== PermissionState::Granted && $state !== PermissionState::Unsupported) {
                $opened[] = $kind->settingsUrl();
            }
        }
        $this->opened = $opened;

        return $opened;
    }
}
