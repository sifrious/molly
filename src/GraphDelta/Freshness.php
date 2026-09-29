<?php

namespace Sifrious\Molly\GraphDelta;

enum Freshness: string
{
    case Current = 'current';
    case Stale = 'stale';
    case PartiallyUpdated = 'partially_updated';
    case Unavailable = 'unavailable';
    case Unknown = 'unknown';
}
