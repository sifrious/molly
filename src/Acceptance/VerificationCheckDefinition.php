<?php

namespace Sifrious\Molly\Acceptance;

final readonly class VerificationCheckDefinition
{
    /**
     * @param  list<PermissionKind>  $requiredPermissions
     */
    public function __construct(
        public string $id,
        public string $stage,
        public string $interface,
        public string $requirement,
        public array $requiredPermissions,
    ) {}

    public function requires(PermissionKind $kind): bool
    {
        return in_array($kind, $this->requiredPermissions, true);
    }
}
