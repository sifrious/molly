<?php

return [
    'model' => env('MOLLY_LOCAL_MODEL'),
    'timeout' => 180,
    'test_timeout' => 120,
    'max_files' => 8,
    'max_file_bytes' => 65536,
    'max_attempts' => 3,
    'parallel_checks' => true,
    'ui' => [
        'enabled' => env('MOLLY_UI_ENABLED', false),
        'prefix' => 'molly',
    ],
];
