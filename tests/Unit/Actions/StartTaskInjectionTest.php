<?php

use Sifrious\Molly\Actions\StartTask;
use Sifrious\Molly\Verification\PestAssertionHints;

it('injects PestAssertionHints into StartTask without service location', function () {
    $action = app(StartTask::class);
    $hints = app(PestAssertionHints::class);

    expect($action)->toBeInstanceOf(StartTask::class)
        ->and($hints)->toBeInstanceOf(PestAssertionHints::class);

    $reflection = new ReflectionClass($action);
    $property = $reflection->getProperty('pestAssertionHints');
    $property->setAccessible(true);

    expect($property->getValue($action))->toBeInstanceOf(PestAssertionHints::class);
});
