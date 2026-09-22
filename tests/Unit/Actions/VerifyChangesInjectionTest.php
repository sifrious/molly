<?php

use Sifrious\Molly\Actions\VerifyChanges;
use Sifrious\Molly\Execution\Sandbox;

it('injects Sandbox into VerifyChanges without service location', function () {
    $action = app(VerifyChanges::class);
    $reflection = new ReflectionClass($action);
    $property = $reflection->getProperty('sandbox');
    $property->setAccessible(true);

    expect($action)->toBeInstanceOf(VerifyChanges::class)
        ->and($property->getValue($action))->toBeInstanceOf(Sandbox::class);

    $source = file_get_contents(dirname(__DIR__, 3).'/src/Actions/VerifyChanges.php');
    expect($source)->not->toContain('app(Sandbox::class)');
});
