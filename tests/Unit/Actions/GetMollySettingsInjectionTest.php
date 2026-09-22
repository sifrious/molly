<?php

use Sifrious\Molly\Actions\GetMollySettings;

it('resolves GetMollySettings from the container without local service construction', function () {
    expect(app(GetMollySettings::class))->toBeInstanceOf(GetMollySettings::class);
});
