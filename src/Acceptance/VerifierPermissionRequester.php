<?php

namespace Sifrious\Molly\Acceptance;

interface VerifierPermissionRequester
{
    public function requestMissing(VerifierPermissionSnapshot $snapshot): PermissionRequestResult;
}
