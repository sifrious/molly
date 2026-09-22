<?php

use Sifrious\Molly\Actions\InspectRun;

it('resolves InspectRun from the container without local service construction', function () {
    expect(app(InspectRun::class))->toBeInstanceOf(InspectRun::class);
});
