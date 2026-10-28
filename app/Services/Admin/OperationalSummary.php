<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

class OperationalSummary
{
    private const AI_RESPONSE_CODES = [
        'AI_OUTPUT_TRUNCATED', 'AI_RESPONSE_REFUSED',
        'AI_INVALID_DOCUMENT_LIKE', 'AI_INVALID_READABLE', 'AI_INVALID_CONFIDENT',
        'AI_INVALID_CATEGORY_KEY', 'AI_INVALID_TAG_KEYS', 'AI_INVALID_SUMMARY',
        'AI_INVALID_SUPPLIER', 'AI_INVALID_AMOUNT', 'AI_INVALID_CURRENCY',
        'AI_INVALID_ISSUED_ON', 'AI_INVALID_EXPIRES_ON',
    ];

    public function queues(): array
    {
        $sizes = [];
        foreach (['high', 'default', 'ocr', 'ai', 'low'] as $name) {
            try {
                $sizes[$name] = Queue::size($name);
            } catch (\Throwable) {
                $sizes[$name] = null;
            }
        }
        $horizon = null;
        if (config('queue.default') === 'redis') {
            try {
                $horizon = collect(app(MasterSupervisorRepository::class)->all())->contains(fn ($row) => ($row->status ?? null) === 'running');
            } catch (\Throwable) { /* Stare necunoscuta, fara detalii Redis. */
            }
        }
        try {
            $last = Cache::get('vault:scheduler:last_seen');
        } catch (\Throwable) {
            $last = null;
        }

        return ['sizes' => $sizes, 'horizon' => $horizon, 'scheduler_recent' => is_int($last) && $last <= now()->timestamp && now()->timestamp - $last <= 180];
    }

    public static function safeCode(?string $code): string
    {
        return in_array($code, [...self::AI_RESPONSE_CODES, 'JOB_FAILED', 'PROCESSING_FAILED', 'QUEUE_UNAVAILABLE', 'ACCESS_REVOKED', 'OCR_PROCESS_FAILED', 'OCR_RUNTIME_UNAVAILABLE', 'OCR_OUTPUT_INVALID', 'OCR_TEMPORARY_STORAGE_FAILED', 'INVALID_AI_RESPONSE', 'OLLAMA_UNAVAILABLE', 'OLLAMA_HTTP_ERROR', 'MODEL_NOT_INSTALLED', 'AI_DISABLED', 'UNSUPPORTED_AI_PROVIDER', 'UNSAFE_AI_CONFIGURATION', 'MISSING_GROQ_CREDENTIALS', 'UNSUPPORTED_GROQ_MODEL', 'INVALID_GROQ_CREDENTIALS', 'GROQ_RATE_LIMITED', 'GROQ_HTTP_ERROR', 'GROQ_UNAVAILABLE', 'PROMPT_TOO_LARGE'], true) ? $code : 'PROCESSING_FAILED';
    }

    public static function retryable(?string $code): bool
    {
        if ($code === 'AI_RESPONSE_REFUSED') {
            return false;
        }

        return in_array($code, [...self::AI_RESPONSE_CODES, 'JOB_FAILED', 'PROCESSING_FAILED', 'QUEUE_UNAVAILABLE', 'OCR_PROCESS_FAILED', 'OCR_TEMPORARY_STORAGE_FAILED', 'INVALID_AI_RESPONSE', 'OLLAMA_UNAVAILABLE', 'OLLAMA_HTTP_ERROR', 'GROQ_RATE_LIMITED', 'GROQ_HTTP_ERROR', 'GROQ_UNAVAILABLE'], true);
    }
}
