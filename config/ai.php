<?php

return [
    'enabled' => (bool) env('AI_ENABLED', false),
    'provider' => env('AI_PROVIDER', 'ollama'),
    'default' => env('ASSISTANT_PROVIDER', env('AI_PROVIDER', 'ollama')),
    'default_for_images' => 'gemini',
    'default_for_audio' => 'gemini',
    'default_for_transcription' => 'gemini',
    'default_for_embeddings' => 'ollama',
    'default_for_reranking' => 'cohere',
    'default_for_classification' => 'typesafe',
    'caching' => ['embeddings' => ['cache' => false, 'store' => env('CACHE_STORE', 'database'), 'individually' => true]],
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash-lite'),
        'max_prompt_bytes' => 256000,
    ],
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
    // Configurarea oficiala laravel/ai ramane separata de adaptoarele OCR/analiza
    // de mai sus, astfel incat joburile deja create sa-si pastreze providerul.
    'providers' => [
        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => 'https://generativelanguage.googleapis.com/v1beta/',
            'models' => ['text' => ['default' => env('ASSISTANT_GEMINI_MODEL', env('GEMINI_MODEL', 'gemini-3.5-flash-lite'))]],
        ],
        'ollama' => [
            'driver' => 'ollama',
            'key' => '',
            'url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
            'models' => ['text' => ['default' => env('ASSISTANT_OLLAMA_MODEL', env('OLLAMA_MODEL', 'qwen3.5:4b'))]],
        ],
    ],
];
