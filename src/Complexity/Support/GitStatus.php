<?php

declare(strict_types=1);

namespace Sifrious\Molly\Complexity\Support;

enum GitStatus: string
{
    case Ok = 'ok';
    case NotFound = 'git-not-found';
    case NotARepo = 'not-a-repo';
    case NoCommits = 'no-commits';

    /**
     * The canonical skip_reason string for the report. Never invent
     * per-call phrasing. the panel and tests pin these exact strings.
     */
    public function skipReason(): ?string
    {
        return match ($this) {
            self::Ok => null,
            self::NotFound => 'git binary not available',
            self::NotARepo => 'not a git repository (git rev-parse failed)',
            self::NoCommits => 'no commit history',
        };
    }
}
