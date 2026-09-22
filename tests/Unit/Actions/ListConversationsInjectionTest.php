<?php

use Sifrious\Molly\Actions\ListConversations;

it('resolves ListConversations from the container without local service construction', function () {
    expect(app(ListConversations::class))->toBeInstanceOf(ListConversations::class);
});
