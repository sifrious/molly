<?php

use Sifrious\Molly\Actions\RunTask;
use Sifrious\Molly\Execution\Sandbox;

it('injects Sandbox and run config collaborators into RunTask', function () {
    $action = app(RunTask::class);
    $prop = (new ReflectionClass($action))->getProperty('sandbox');
    $prop->setAccessible(true);
    expect($prop->getValue($action))->toBeInstanceOf(Sandbox::class);
});
