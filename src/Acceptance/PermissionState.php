<?php

namespace Sifrious\Molly\Acceptance;

enum PermissionState: string
{
    case Granted = 'granted';
    case Denied = 'denied';
    case NotDetermined = 'not_determined';
    case Unsupported = 'unsupported';
    case Unknown = 'unknown';

    public function isGranted(): bool
    {
        return $this === self::Granted;
    }
}
