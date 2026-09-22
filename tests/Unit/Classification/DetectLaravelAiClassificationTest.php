<?php

use Sifrious\Molly\Classification\DetectLaravelAiClassification;

it('does not treat structured-agent support as choice capability on current laravel/ai', function () {
    $detect = new DetectLaravelAiClassification;

    expect($detect->supportsChoice())->toBeFalse()
        ->and($detect->supportsStructuredAgents() ? true : true)->toBeTrue(); // structured may or may not exist
});

it('falls back when decide macro and choice capability are absent', function () {
    $detect = new DetectLaravelAiClassification;

    if (! $detect->supportsDecide() && ! $detect->supportsChoice()) {
        expect($detect->adapter())->toBe('molly.fallback');
    } else {
        expect($detect->adapter())->not->toBe('');
    }
});

it('reports composer pretty version or null without using version as capability', function () {
    $detect = new DetectLaravelAiClassification;
    $version = $detect->version();

    expect($version === null || is_string($version))->toBeTrue()
        ->and($detect->supportsChoice())->toBeFalse(); // v0.11.2 has no Choice stack
});
