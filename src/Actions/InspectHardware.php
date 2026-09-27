<?php

namespace Sifrious\Molly\Actions;

use Sifrious\Molly\Hardware\HardwareProbe;
use Sifrious\Molly\Hardware\HardwareSnapshot;

/**
 * Measure this host for local model preflight and return a molly.hardware-snapshot/1
 * document. Model selection is not part of this action.
 */
class InspectHardware
{
    public function __construct(private HardwareProbe $probe) {}

    /** @return array<string, mixed> */
    public function handle(?string $destination = null): array
    {
        $facts = $this->probe->facts($destination);

        return HardwareSnapshot::make($facts, [
            'hostname' => gethostname() ?: null,
            'php_os_family' => PHP_OS_FAMILY,
            'php_version' => PHP_VERSION,
        ], now()->utc()->toIso8601ZuluString());
    }
}
