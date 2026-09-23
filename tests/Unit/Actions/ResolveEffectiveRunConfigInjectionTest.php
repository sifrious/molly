<?php

use Sifrious\Molly\Actions\ResolveEffectiveRunConfig;

it('resolves ResolveEffectiveRunConfig from the container without local service construction', function () {
    expect(app(ResolveEffectiveRunConfig::class))->toBeInstanceOf(ResolveEffectiveRunConfig::class);
});
