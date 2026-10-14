<?php

namespace Sifrious\Molly\Acceptance;

enum PermissionHistoryDisposition: string
{
    case NeverChecked = 'never_checked';
    case CheckedMissing = 'checked_missing';
    case Requested = 'requested';
    case Granted = 'granted';
    case Denied = 'denied';
}
