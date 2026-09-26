<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Sifrious\Molly\Agents\TarpitReviewer;

it('asks the model for non-empty evidence on every check', function () {
    $schema = (new TarpitReviewer)->schema(new JsonSchemaTypeFactory);

    foreach (range('A', 'G') as $code) {
        $check = $schema['checks']->toArray()['properties'][$code];

        expect($check['properties']['evidence']['minLength'] ?? null)->toBe(1, "check $code");
    }
});
