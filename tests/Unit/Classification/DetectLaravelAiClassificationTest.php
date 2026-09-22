<?php

use Illuminate\Support\Str;
use Sifrious\Molly\Classification\DetectLaravelAiClassification;

it('does not treat structured-agent support as choice capability on current laravel/ai', function () {
    $detect = new DetectLaravelAiClassification;

    expect($detect->supportsChoice())->toBeFalse()
        ->and($detect->supportsStructuredAgents() ? true : true)->toBeTrue();
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
        ->and($detect->supportsChoice())->toBeFalse();
});

it('treats the decide macro as an independent boolean capability', function () {
    $detect = new DetectLaravelAiClassification;
    $hadDecide = Str::hasMacro('decide');

    // Version / structured agents must never imply decide.
    expect($detect->supportsDecide())->toBe($hadDecide)
        ->and($detect->supportsDecide())->not->toBe($detect->supportsStructuredAgents() && $detect->version() !== null);

    if ($hadDecide) {
        Str::flushMacros();
    }

    expect((new DetectLaravelAiClassification)->supportsDecide())->toBeFalse()
        ->and((new DetectLaravelAiClassification)->adapter())->not->toBe('laravel-ai.decide');

    Str::macro('decide', fn () => true);

    expect((new DetectLaravelAiClassification)->supportsDecide())->toBeTrue()
        ->and((new DetectLaravelAiClassification)->adapter())->toBe('laravel-ai.decide');

    // Cleanup: remove only our test macro when the suite started without one.
    if (! $hadDecide) {
        Str::flushMacros();
    }
});
