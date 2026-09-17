<?php

return [
    'agent' => env('MOLLY_AGENT', 'ollama'),
    'model' => env('MOLLY_LOCAL_MODEL'),
    'timeout' => 180,
    'test_timeout' => 120,
    'max_files' => 8,
    'max_file_bytes' => 65536,
    'max_attempts' => 3,
    'parallel_checks' => true,
    'typesafe' => [
        'enabled' => false,
        'api_key' => env('TYPESAFE_API_KEY'),
        'model' => 'jev-latest',
        'confidence_threshold' => 0.8,
        'timeout' => 30,
        'instructions' => 'Which next action does this task and its test and review evidence support?',
    ],
    'ui' => [
        'enabled' => env('MOLLY_UI_ENABLED', false),
        'prefix' => 'molly',
    ],
];
