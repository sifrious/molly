<?php

use Sifrious\Molly\Actions\ListMollyProjects;

it('resolves ListMollyProjects from the container without local service construction', function () {
    expect(app(ListMollyProjects::class))->toBeInstanceOf(ListMollyProjects::class);
});
