<?php

namespace Sifrious\Molly\GraphDelta;

enum VerificationStatus: string
{
    case Verified = 'verified';
    case ReviewRequired = 'review_required';
    case Blocked = 'blocked';
    case Unresolved = 'unresolved';
}
