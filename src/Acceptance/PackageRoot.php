<?php

namespace Sifrious\Molly\Acceptance;

final class PackageRoot
{
    public static function path(): string
    {
        return dirname(__DIR__, 2);
    }
}
