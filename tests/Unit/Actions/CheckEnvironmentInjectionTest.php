<?php

use Sifrious\Molly\Actions\CheckEnvironment;
use Sifrious\Molly\Complexity\Clever;
use Sifrious\Molly\Execution\Sandbox;

it('injects Sandbox and Clever into CheckEnvironment', function () {
    $action = app(CheckEnvironment::class);
    $r = new ReflectionClass($action);
    foreach ([('sandbox', Sandbox::class), ('clever', Clever::class)] as [$name, $class]) {
        $prop = $r->getProperty($name);
        $prop->setAccessible(true);
        expect($prop->getValue($action))->toBeInstanceOf($class);
    }
});
