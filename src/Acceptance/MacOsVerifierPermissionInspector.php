<?php

namespace Sifrious\Molly\Acceptance;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Process;

final class MacOsVerifierPermissionInspector implements VerifierPermissionInspector
{
    public function __construct(private MacOsTccProbe $probe) {}

    public function inspect(): VerifierPermissionSnapshot
    {
        $process = VerifierProcessIdentity::capture();
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $read = $this->probe->read(false);
        if ($read === null) {
            return VerifierPermissionSnapshot::unsupported($process, $at);
        }

        return new VerifierPermissionSnapshot(
            $read['screen_recording'] ? PermissionState::Granted : PermissionState::NotDetermined,
            $read['accessibility'] ? PermissionState::Granted : PermissionState::NotDetermined,
            $process,
            'Darwin',
            $this->macosVersion(),
            php_uname('n'),
            $at,
        );
    }

    private function macosVersion(): ?string
    {
        $result = Process::timeout(5)->run(['sw_vers', '-productVersion']);
        $version = trim($result->output());

        return $result->successful() && $version !== '' ? $version : null;
    }
}
