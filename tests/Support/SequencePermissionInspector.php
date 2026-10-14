<?php

namespace Sifrious\Molly\Tests\Support;

use RuntimeException;
use Sifrious\Molly\Acceptance\VerifierPermissionInspector;
use Sifrious\Molly\Acceptance\VerifierPermissionSnapshot;

final class SequencePermissionInspector implements VerifierPermissionInspector
{
    /** @param  list<VerifierPermissionSnapshot>  $snapshots */
    public function __construct(private array $snapshots) {}

    public function inspect(): VerifierPermissionSnapshot
    {
        $next = array_shift($this->snapshots);
        if (! $next instanceof VerifierPermissionSnapshot) {
            throw new RuntimeException('INSPECTOR_EXHAUSTED: The test inspector has no further snapshot.');
        }

        return $next;
    }
}
