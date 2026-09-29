<?php

namespace Sifrious\Molly\ModelFit;

use InvalidArgumentException;
use RuntimeException;

/**
 * The one memory and disk rule for local models. A model has headroom when its size
 * plus molly.memory.headroom_gb fits in the memory being compared: total memory when
 * Molly decides which model fits this Mac, and available memory when a run is about
 * to load the model. A download fits the destination volume when at least
 * MINIMUM_FREE_DISK_FRACTION of the volume stays free afterwards.
 */
final readonly class InstallationHeadroom
{
    public const MINIMUM_FREE_DISK_FRACTION = 0.15;

    public function __construct(
        public int $memoryBytes,
        public float $minimumFreeDiskFraction = self::MINIMUM_FREE_DISK_FRACTION,
    ) {
        if ($memoryBytes < 0 || $minimumFreeDiskFraction < 0 || $minimumFreeDiskFraction >= 1) {
            throw new InvalidArgumentException('Installation headroom needs a memory budget of 0 bytes or more and a free disk fraction from 0 up to 1.');
        }
    }

    public static function fromConfig(): self
    {
        $headroom = config('molly.memory.headroom_gb', 11);
        if (! is_numeric($headroom) || (float) $headroom < 0) {
            throw new RuntimeException('MEMORY_HEADROOM_INVALID: Set molly.memory.headroom_gb to a number of gigabytes, 0 or more.');
        }

        return new self((int) round((float) $headroom * 1e9));
    }

    /** The memory a model of this size needs so that the headroom stays free. */
    public function memoryRequired(int $modelBytes): int
    {
        return $modelBytes + $this->memoryBytes;
    }

    public function fitsMemory(int $modelBytes, int $memoryBytes): bool
    {
        return $this->memoryRequired($modelBytes) <= $memoryBytes;
    }

    /** The free space a download needs so that the minimum fraction of the volume stays free. */
    public function diskRequired(int $downloadBytes, int $volumeBytes): int
    {
        return $downloadBytes + (int) ceil($this->minimumFreeDiskFraction * $volumeBytes);
    }

    public function fitsDisk(int $downloadBytes, int $freeBytes, int $volumeBytes): bool
    {
        return $this->diskRequired($downloadBytes, $volumeBytes) <= $freeBytes;
    }

    /** Bytes as gigabytes of 10^9 bytes with one decimal, the unit molly.memory.headroom_gb uses. */
    public static function gigabytes(int|float $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1e9, 1, '.', ''), '0'), '.').' GB';
    }
}
