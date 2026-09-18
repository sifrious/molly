<?php

namespace Sifrious\Molly\Contracts;

enum DispatchDecision: string
{
    case Needed = 'needed';
    case AlreadyAccepted = 'already_accepted';
    case Unconfirmed = 'unconfirmed';
}
