<?php

return [
    'enabled' => (bool) env('AI_ENABLED', false),
    'provider' => env('AI_PROVIDER', 'ollama'),
    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'openai/gpt-oss-20b'),
        // Limita conservatoare in bytes pentru mesaje + schema, nu numar de tokenuri.
        'max_prompt_bytes' => 64000,
    ],
    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen3.5:4b'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 90),
        'context_length' => (int) env('OLLAMA_NUM_CTX', 16384),
        'max_output_tokens' => 1024,
        'max_sections' => 4,
    ],
];
