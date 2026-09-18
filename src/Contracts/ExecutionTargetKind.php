<?php

namespace Sifrious\Molly\Contracts;

enum ExecutionTargetKind: string
{
    case Local = 'local';
    case Orb = 'orb';
}
