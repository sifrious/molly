<?php

namespace Sifrious\Molly\GraphDelta;

enum ChangeType: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Changed = 'changed';
}
