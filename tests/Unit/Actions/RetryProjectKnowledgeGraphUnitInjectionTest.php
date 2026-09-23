<?php

use Sifrious\Molly\Actions\RetryProjectKnowledgeGraphUnit;

it('resolves RetryProjectKnowledgeGraphUnit from the container without local service construction', function () {
    expect(app(RetryProjectKnowledgeGraphUnit::class))->toBeInstanceOf(RetryProjectKnowledgeGraphUnit::class);
});
