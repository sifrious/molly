<?php

use Sifrious\Molly\MollyServiceProvider;

it('groups MollyServiceProvider boot into named private methods', function () {
    $provider = new MollyServiceProvider(app());
    $r = new ReflectionClass($provider);
    foreach (['bootMcp', 'bootPackageResources', 'bootLivewire', 'bootConsole', 'registerCommands', 'publishConfig'] as $method) {
        expect($r->hasMethod($method))->toBeTrue();
        expect($r->getMethod($method)->isPrivate())->toBeTrue();
    }
});
