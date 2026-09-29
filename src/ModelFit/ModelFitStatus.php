<?php

namespace Sifrious\Molly\ModelFit;

/**
 * The outcome of a fit decision. Unknown means Molly could not measure a fact the
 * decision needs; it never means incompatible, and it never allows a download.
 */
enum ModelFitStatus: string
{
    case Unsupported = 'unsupported';
    case MinimumFit = 'minimum_fit';
    case RecommendedFit = 'recommended_fit';
    case AlreadyInstalled = 'already_installed';
    case NoFit = 'no_fit';
    case Unknown = 'unknown';

    public function fits(): bool
    {
        return in_array($this, [self::MinimumFit, self::RecommendedFit, self::AlreadyInstalled], true);
    }
}
