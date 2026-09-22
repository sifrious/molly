<?php

use Sifrious\Molly\Actions\GenerateChanges;
use Sifrious\Molly\Agents\AmpResponse;
use Sifrious\Molly\Verification\PestTestAuthoring;

it('injects AmpResponse and PestTestAuthoring into GenerateChanges without service location', function () {
    $action = app(GenerateChanges::class);

    $reflection = new ReflectionClass($action);
    $amp = $reflection->getProperty('amp');
    $amp->setAccessible(true);
    $authoring = $reflection->getProperty('pestTestAuthoring');
    $authoring->setAccessible(true);

    expect($action)->toBeInstanceOf(GenerateChanges::class)
        ->and($amp->getValue($action))->toBeInstanceOf(AmpResponse::class)
        ->and($authoring->getValue($action))->toBeInstanceOf(PestTestAuthoring::class);

    $source = file_get_contents(dirname(__DIR__, 3).'/src/Actions/GenerateChanges.php');
    expect($source)->not->toContain('app(AmpResponse::class)')
        ->and($source)->not->toContain('app(PestTestAuthoring::class)');
});

it('keeps provider acquisition separate from proposal validation', function () {
    $reflection = new ReflectionClass(GenerateChanges::class);

    expect($reflection->hasMethod('acquireProposal'))->toBeTrue()
        ->and($reflection->hasMethod('validateProposal'))->toBeTrue()
        ->and($reflection->getMethod('acquireProposal')->isPrivate())->toBeTrue()
        ->and($reflection->getMethod('validateProposal')->isPrivate())->toBeTrue();

    $handle = file_get_contents(dirname(__DIR__, 3).'/src/Actions/GenerateChanges.php');
    expect($handle)->toContain('return $this->validateProposal(')
        ->and($handle)->toContain('$this->acquireProposal($input)');
});
