<?php

use Sifrious\Molly\Actions\RecommendTaskNextStep;
use Sifrious\Molly\Actions\ShowTask;

it('injects ShowTask into RecommendTaskNextStep', function () {
    $action = app(RecommendTaskNextStep::class);
    $prop = (new ReflectionClass($action))->getProperty('showTask');
    $prop->setAccessible(true);
    expect($prop->getValue($action))->toBeInstanceOf(ShowTask::class);
});
