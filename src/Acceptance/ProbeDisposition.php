<?php

namespace Sifrious\Molly\Acceptance;

enum ProbeDisposition: string
{
    case Complete = 'complete';
    case NeedsNative = 'needs_native';
}
