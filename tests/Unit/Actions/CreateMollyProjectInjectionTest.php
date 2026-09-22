<?php

use Sifrious\Molly\Actions\CreateMollyProject;

it('resolves CreateMollyProject from the container without local service construction', function () {
    expect(app(CreateMollyProject::class))->toBeInstanceOf(CreateMollyProject::class);
});
