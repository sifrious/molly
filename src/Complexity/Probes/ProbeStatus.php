<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Probes;

enum ProbeStatus: string
{
    case Ok = 'ok';
    case Skipped = 'skipped';
    case Error = 'error';
}
