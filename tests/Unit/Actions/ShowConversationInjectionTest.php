<?php

use Sifrious\Molly\Actions\ShowConversation;

it('resolves ShowConversation from the container without local service construction', function () {
    expect(app(ShowConversation::class))->toBeInstanceOf(ShowConversation::class);
});
