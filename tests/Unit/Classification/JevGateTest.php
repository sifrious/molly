<?php

use Sifrious\Molly\Classification\ChoiceClassifier;
use Sifrious\Molly\Classification\DetectLaravelAiClassification;
use Sifrious\Molly\Classification\JevGate;
use Sifrious\Molly\Classification\LaravelAiChoiceClassifier;
use Sifrious\Molly\Tests\Support\FakeChoiceClassifier;

it('is closed by default and reports jev_disabled', function () {
    expect(config('molly.jev.enabled'))->toBeFalse();
    $gate = app(JevGate::class);

    expect($gate->enabled())->toBeFalse()
        ->and($gate->available())->toBeFalse()
        ->and($gate->ready())->toBeFalse()
        ->and($gate->status())->toBe(JevGate::DISABLED)
        ->and($gate->reason())->toBe('jev_disabled');
});

it('stays closed for every non-true enabled value', function (mixed $value) {
    config(['molly.jev.enabled' => $value, 'ai.providers.typesafe.key' => 'test-key']);
    app()->instance(ChoiceClassifier::class, new FakeChoiceClassifier);

    expect(app(JevGate::class)->status())->toBe(JevGate::DISABLED);
})->with(['string true' => ['true'], 'int one' => [1], 'yes' => ['yes'], 'null' => [null]]);

it('reports the enabled-but-unavailable state explicitly', function () {
    fakeJev(available: false);
    $gate = app(JevGate::class);

    expect($gate->enabled())->toBeTrue()
        ->and($gate->available())->toBeFalse()
        ->and($gate->ready())->toBeFalse()
        ->and($gate->status())->toBe(JevGate::UNAVAILABLE)
        ->and($gate->reason())->toBe('capability_missing');
});

it('requires a Laravel AI TypeSafe credential before it is ready', function (mixed $key) {
    fakeJev();
    config(['ai.providers.typesafe.key' => $key]);
    $gate = app(JevGate::class);

    expect($gate->available())->toBeTrue()
        ->and($gate->ready())->toBeFalse()
        ->and($gate->status())->toBe(JevGate::UNCONFIGURED)
        ->and($gate->reason())->toBe('invalid_config');
})->with(['null' => [null], 'empty' => [''], 'blank' => ['   '], 'not a string' => [42]]);

it('is ready when enabled, capable, and configured', function () {
    fakeJev();
    $gate = app(JevGate::class);

    expect($gate->status())->toBe(JevGate::READY)->and($gate->reason())->toBeNull();
});

it('binds the Laravel AI classifier in production and reports the installed capability honestly', function () {
    $classifier = app(ChoiceClassifier::class);

    expect($classifier)->toBeInstanceOf(LaravelAiChoiceClassifier::class)
        ->and($classifier->available())->toBe(app(DetectLaravelAiClassification::class)->supportsChoice());

    config(['molly.jev.enabled' => true, 'ai.providers.typesafe.key' => 'test-key']);

    expect(app(JevGate::class)->status())->toBe($classifier->available() ? JevGate::READY : JevGate::UNAVAILABLE);
});
