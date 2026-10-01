<?php

namespace Sifrious\Molly\Workspace;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/** Finds the git executable on PATH, so a missing Git is named before Molly writes anything. */
final class GitBinary
{
    public static function find(): ?string
    {
        return (new ExecutableFinder)->find('git');
    }

    public static function require(): string
    {
        return self::find() ?? throw new RuntimeException('GIT_MISSING: Molly needs the git executable, and no git was found on PATH ('.(getenv('PATH') ?: 'empty').'). Install Git or add it to PATH, then run the command again.');
    }
}
