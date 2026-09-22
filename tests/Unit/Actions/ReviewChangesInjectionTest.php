<?php

use Sifrious\Molly\Actions\ReviewChanges;
use Sifrious\Molly\Agents\AmpResponse;

it('injects AmpResponse into ReviewChanges', function () {
    $action = app(ReviewChanges::class);
    $prop = (new ReflectionClass($action))->getProperty('ampResponse');
    $prop->setAccessible(true);
    expect($prop->getValue($action))->toBeInstanceOf(AmpResponse::class);
});
