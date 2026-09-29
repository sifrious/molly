<?php

namespace Sifrious\Molly\GraphDelta;

enum Section: string
{
    case ChangedDirectly = 'changed_directly';
    case AffectedContext = 'affected_context';
    case TestsContracts = 'tests_contracts';
    case UnknownImpact = 'unknown_impact';
    case VisualChanges = 'visual_changes';
}
