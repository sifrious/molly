<?php

use Sifrious\Molly\Actions\CheckEnvironment;
use Sifrious\Molly\Complexity\Clever;
use Sifrious\Molly\Execution\Sandbox;

it('injects Sandbox and Clever into CheckEnvironment', function () {
    $action = app(CheckEnvironment::class);
    $r = new ReflectionClass($action);

    $sandbox = $r->getProperty('sandbox');
    $sandbox->setAccessible(true);
    $clever = $r->getProperty('clever');
    $clever->setAccessible(true);

    expect($sandbox->getValue($action))->toBeInstanceOf(Sandbox::class)
        ->and($clever->getValue($action))->toBeInstanceOf(Clever::class);
});
