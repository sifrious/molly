<?php

use Sifrious\Molly\ModelFit\InstallationHeadroom;

it('reads the memory headroom from molly.memory.headroom_gb', function (mixed $gigabytes, int $bytes) {
    config(['molly.memory.headroom_gb' => $gigabytes]);

    expect(InstallationHeadroom::fromConfig())->toMatchObject(['memoryBytes' => $bytes, 'minimumFreeDiskFraction' => 0.15]);
})->with([
    'default' => [11, 11_000_000_000],
    'fraction' => [0.5, 500_000_000],
    'numeric string' => ['8', 8_000_000_000],
    'zero' => [0, 0],
]);

it('refuses a headroom that is not a number of gigabytes, 0 or more', function (mixed $gigabytes) {
    config(['molly.memory.headroom_gb' => $gigabytes]);

    expect(fn () => InstallationHeadroom::fromConfig())->toThrow(RuntimeException::class, 'MEMORY_HEADROOM_INVALID: Set molly.memory.headroom_gb to a number of gigabytes, 0 or more.');
})->with([-1, 'lots', null]);

it('fits memory at the exact threshold and not one byte below', function () {
    $headroom = new InstallationHeadroom(11_000_000_000);

    expect($headroom->memoryRequired(13_793_441_244))->toBe(24_793_441_244)
        ->and($headroom->fitsMemory(13_793_441_244, 24_793_441_244))->toBeTrue()
        ->and($headroom->fitsMemory(13_793_441_244, 24_793_441_243))->toBeFalse()
        ->and($headroom->fitsMemory(13_793_441_244, 24_793_441_245))->toBeTrue();
});

it('keeps 15 percent of the volume free after a download', function () {
    $headroom = new InstallationHeadroom(0);

    expect($headroom->diskRequired(100, 1000))->toBe(250)
        ->and($headroom->diskRequired(100, 1001))->toBe(251)
        ->and($headroom->fitsDisk(100, 250, 1000))->toBeTrue()
        ->and($headroom->fitsDisk(100, 249, 1000))->toBeFalse();
});

it('refuses budgets outside their ranges', function (int $memory, float $fraction) {
    expect(fn () => new InstallationHeadroom($memory, $fraction))->toThrow(InvalidArgumentException::class);
})->with([[-1, 0.15], [0, -0.1], [0, 1.0]]);

it('formats bytes as gigabytes of 10^9 bytes', function () {
    expect(InstallationHeadroom::gigabytes(65_369_818_623))->toBe('65.4 GB')
        ->and(InstallationHeadroom::gigabytes(11_000_000_000))->toBe('11 GB')
        ->and(InstallationHeadroom::gigabytes(0))->toBe('0 GB');
});
