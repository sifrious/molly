<?php

namespace Sifrious\Molly\Acceptance;

final class MacOsVerifierPermissionRequester implements VerifierPermissionRequester
{
    public function __construct(
        private VerifierPermissionInspector $inspector,
        private MacOsPermissionPrompt $prompt,
    ) {}

    public function requestMissing(VerifierPermissionSnapshot $snapshot): PermissionRequestResult
    {
        $attempted = $this->prompt->request($snapshot);
        $after = $this->inspector->inspect();
        $opened = $attempted ? $this->prompt->openSettings($after->denyWhereRequestDidNotGrant()) : [];

        return PermissionRequestResult::fromInspection($snapshot, $after, $attempted, $opened);
    }
}
