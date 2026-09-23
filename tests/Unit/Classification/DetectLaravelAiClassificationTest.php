<?php

use Illuminate\Support\Str;
use Sifrious\Molly\Classification\DetectLaravelAiClassification;

/**
 * The exact surface Molly needs, computed independently of the detector so the
 * same test is honest on the stable baseline and on the accepted Laravel AI commit.
 */
function choiceSurfaceInstalled(): bool
{
    $lab = 'Laravel\\Ai\\Enums\\Lab';

    return class_exists('Laravel\\Ai\\Classification')
        && class_exists('Laravel\\Ai\\Classification\\Choice')
        && class_exists('Laravel\\Ai\\Responses\\Data\\ChoiceAnswer')
        && class_exists('Laravel\\Ai\\PendingResponses\\PendingClassification')
        && enum_exists($lab)
        && in_array('TypeSafe', array_column($lab::cases(), 'name'), true);
}

it('reports choice capability only when the exact classification surface is installed', function () {
    $detect = new DetectLaravelAiClassification;

    expect($detect->supportsChoice())->toBe(choiceSurfaceInstalled());
});

it('does not treat structured-agent support or package presence as choice capability', function () {
    $detect = new DetectLaravelAiClassification;

    if (choiceSurfaceInstalled()) {
        $this->markTestSkipped('This lane has the full classification surface, so the negative proof does not apply.');
    }

    expect($detect->supportsStructuredAgents() || $detect->version() !== null)->toBeTrue()
        ->and($detect->supportsChoice())->toBeFalse();
});

it('falls back when decide macro and choice capability are absent', function () {
    $detect = new DetectLaravelAiClassification;

    if (! $detect->supportsDecide() && ! $detect->supportsChoice()) {
        expect($detect->adapter())->toBe('molly.fallback');
    } else {
        expect($detect->adapter())->toBeIn(['laravel-ai.decide', 'laravel-ai.choice']);
    }
});

it('reports composer pretty version or null without using version as capability', function () {
    $detect = new DetectLaravelAiClassification;
    $version = $detect->version();

    expect($version === null || (is_string($version) && $version !== ''))->toBeTrue()
        ->and($detect->supportsChoice())->toBe(choiceSurfaceInstalled())
        ->and($detect->supportsDecide())->toBe(Str::hasMacro('decide'));
});

it('treats the decide macro as an independent boolean capability', function () {
    $detect = new DetectLaravelAiClassification;
    $hadDecide = Str::hasMacro('decide');

    expect($detect->supportsDecide())->toBe($hadDecide);

    if ($hadDecide) {
        // The macro is the capability. Version text and structured-agent support never stand in for it.
        expect($detect->adapter())->toBe('laravel-ai.decide');

        return;
    }

    expect((new DetectLaravelAiClassification)->adapter())->not->toBe('laravel-ai.decide');

    Str::macro('decide', fn () => true);

    try {
        expect((new DetectLaravelAiClassification)->supportsDecide())->toBeTrue()
            ->and((new DetectLaravelAiClassification)->adapter())->toBe('laravel-ai.decide');
    } finally {
        Str::flushMacros();
    }

    expect((new DetectLaravelAiClassification)->supportsDecide())->toBeFalse();
});

it('journals can record version while selection stays feature-based', function () {
    $detect = new DetectLaravelAiClassification;
    $version = $detect->version();

    expect($version === null || (is_string($version) && $version !== ''))->toBeTrue();

    $adapter = $detect->adapter();
    expect($adapter)->toBeIn(['laravel-ai.decide', 'laravel-ai.choice', 'molly.fallback']);

    if (! Str::hasMacro('decide') && ! choiceSurfaceInstalled()) {
        expect($adapter)->toBe('molly.fallback');
    }
});
