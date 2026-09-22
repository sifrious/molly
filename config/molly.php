<?php

return [
    'agent' => env('MOLLY_AGENT', 'ollama'),
    'model' => env('MOLLY_LOCAL_MODEL'),
    'timeout' => 180,
    'test_timeout' => 120,
    'max_files' => 8,
    'max_file_bytes' => 65536,
    'max_attempts' => 3,

    'agent_bus' => [
        'lease_seconds' => 120,
        'worker_id' => null,
    ],
    'parallel_checks' => true,
    'verification' => [
        'pest' => 'required',
        'tarpit' => 'required',
        'parallel_join' => 'required',
        'false_green' => 'required',
    ],
    'verification_actions' => [
        'pest' => 'retry',
        'tarpit' => 'retry',
        'parallel_join' => 'retry',
        'false_green' => 'fail',
    ],
    'false_green' => [
        'enabled' => env('MOLLY_FALSE_GREEN', false),
        'max_mutations' => 2,
        'timeout_seconds' => 30,
    ],
    'sandbox' => [
        'allow_unsafe' => env('MOLLY_SANDBOX_ALLOW_UNSAFE', false),
    ],
    'knowledge' => [
        'database' => env('MOLLY_KNOWLEDGE_DATABASE', '.molly/knowledge.sqlite'),
    ],
    'preview' => [
        'command' => env('MOLLY_PREVIEW_COMMAND'),
        'url' => env('MOLLY_PREVIEW_URL'),
        'viewport' => env('MOLLY_PREVIEW_VIEWPORT', '1280x720'),
    ],
    'jev' => [
        'enabled' => env('MOLLY_JEV_ENABLED', true),
    ],
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
