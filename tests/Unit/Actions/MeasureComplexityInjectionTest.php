<?php

use Sifrious\Molly\Actions\MeasureComplexity;
use Sifrious\Molly\Complexity\Clever;

it('injects Clever into MeasureComplexity without service location', function () {
    $action = app(MeasureComplexity::class);
    $reflection = new ReflectionClass($action);
    $property = $reflection->getProperty('clever');
    $property->setAccessible(true);

    expect($action)->toBeInstanceOf(MeasureComplexity::class)
        ->and($property->getValue($action))->toBeInstanceOf(Clever::class);

    $source = file_get_contents(dirname(__DIR__, 3).'/src/Actions/MeasureComplexity.php');
    expect($source)->not->toContain('app(Clever::class)');
});
