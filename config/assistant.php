<?php

return [
    'enabled' => (bool) env('ASSISTANT_ENABLED', false),
    'provider' => env('ASSISTANT_PROVIDER', env('AI_PROVIDER', 'ollama')),
    'model' => env('ASSISTANT_MODEL'),
    'cache_store' => env('ASSISTANT_CACHE_STORE', 'redis'),
    'daily_messages' => ['free' => 20, 'pro' => 100],
    'global_concurrency' => 5,
    'concurrency_wait_seconds' => 3,
    'timeout_seconds' => 60,
    'lock_seconds' => 75,
    'max_tokens' => 500,
    'max_history_messages' => 10,
    'max_message_characters' => 4000,
    'max_document_bytes' => 120000,
];
