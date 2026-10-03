<?php

namespace Sifrious\Molly\Acceptance;

use Illuminate\Support\Facades\Process;

/**
 * Asks macOS to show its permission prompt, then opens Settings when the
 * permission is still missing. Neither call grants TCC by itself.
 */
class MacOsPermissionPrompt
{
    public function __construct(private MacOsTccProbe $probe) {}

    public function request(VerifierPermissionSnapshot $before): bool
    {
        if (PHP_OS_FAMILY !== 'Darwin' || ! $before->supportsRequest()) {
            return false;
        }

        $this->probe->read(true);

        return true;
    }

    /** @return list<string> */
    public function openSettings(VerifierPermissionSnapshot $after): array
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return [];
        }

        $opened = [];
        foreach (PermissionKind::cases() as $kind) {
            $state = $after->state($kind);
            if ($state === PermissionState::Granted || $state === PermissionState::Unsupported) {
                continue;
            }
            $url = $kind->settingsUrl();
            $result = Process::timeout(10)->run(['/usr/bin/open', $url]);
            if ($result->successful()) {
                $opened[] = $url;
            }
        }

        return $opened;
    }
}
