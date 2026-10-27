<?php

return [
    'enabled' => (bool) env('AI_ENABLED', false),
    'provider' => env('AI_PROVIDER', 'ollama'),
    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen3.5:4b'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 90),
        'context_length' => (int) env('OLLAMA_NUM_CTX', 4096),
        'max_output_tokens' => 512,
    ],
];
