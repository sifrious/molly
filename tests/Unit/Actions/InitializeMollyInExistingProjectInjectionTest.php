<?php

use Sifrious\Molly\Actions\InitializeMollyInExistingProject;

it('resolves InitializeMollyInExistingProject from the container without local service construction', function () {
    expect(app(InitializeMollyInExistingProject::class))->toBeInstanceOf(InitializeMollyInExistingProject::class);
});
