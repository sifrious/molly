<?php

namespace Sifrious\Molly\Acceptance;

enum VerificationMode: string
{
    case Default = 'default';
    case Permissionless = 'permissionless';
    case CheckPermissions = 'check_permissions';
    case RetryNativeUi = 'retry_native_ui';

    public function requestsPermissions(): bool
    {
        return $this === self::Default || $this === self::RetryNativeUi;
    }
}
