<?php

namespace Sifrious\Molly\Verification;

enum FailureAction: string
{
    case Retry = 'retry';
    case Fail = 'fail';
    case Warn = 'warn';
}
