<?php

return [
    // Manifest versionat: un rand DB nou nu publica automat o limba.
    'locales' => ['ro' => 'RON', 'en' => 'EUR', 'hu' => 'HUF', 'bg' => 'EUR'],
    'plans' => ['free' => ['max_file_bytes' => 5 * 1024 * 1024, 'max_pages' => 10, 'monthly_ocr_pages' => (int) env('VAULT_FREE_OCR_PAGES', 50), 'monthly_ai_calls' => (int) env('VAULT_FREE_AI_CALLS', 10), 'storage_bytes' => (int) env('VAULT_FREE_STORAGE_BYTES', 100 * 1024 * 1024)]],
    'display_timezone' => 'Europe/Bucharest',
    'pdftoppm_binary' => env('PDFTOPPM_BINARY', 'pdftoppm'),
    'qpdf_binary' => env('QPDF_BINARY', 'qpdf'),
    'public_pages' => ['home', 'features', 'pricing', 'faq'],
    'rate_limits' => ['registration_per_hour' => 3],
];
