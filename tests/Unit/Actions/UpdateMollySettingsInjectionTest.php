<?php

use Sifrious\Molly\Actions\UpdateMollySettings;

it('resolves UpdateMollySettings from the container without local service construction', function () {
    expect(app(UpdateMollySettings::class))->toBeInstanceOf(UpdateMollySettings::class);
});
