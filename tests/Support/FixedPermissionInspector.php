<?php

namespace Sifrious\Molly\Tests\Support;

use Sifrious\Molly\Acceptance\VerifierPermissionInspector;
use Sifrious\Molly\Acceptance\VerifierPermissionSnapshot;

final class FixedPermissionInspector implements VerifierPermissionInspector
{
    public function __construct(private VerifierPermissionSnapshot $snapshot) {}

    public function inspect(): VerifierPermissionSnapshot
    {
        return $this->snapshot;
    }
}
