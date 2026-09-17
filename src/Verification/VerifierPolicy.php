<?php

namespace Sifrious\Molly\Verification;

enum VerifierPolicy: string
{
    case Required = 'required';
    case Advisory = 'advisory';
}
