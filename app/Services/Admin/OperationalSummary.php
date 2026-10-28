<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

class OperationalSummary
{
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
        return in_array($code, ['JOB_FAILED', 'PROCESSING_FAILED', 'QUEUE_UNAVAILABLE', 'ACCESS_REVOKED', 'OCR_PROCESS_FAILED', 'OCR_RUNTIME_UNAVAILABLE', 'OCR_OUTPUT_INVALID', 'OCR_TEMPORARY_STORAGE_FAILED', 'INVALID_AI_RESPONSE', 'OLLAMA_UNAVAILABLE', 'OLLAMA_HTTP_ERROR', 'MODEL_NOT_INSTALLED', 'AI_DISABLED', 'UNSUPPORTED_AI_PROVIDER', 'UNSAFE_AI_CONFIGURATION', 'MISSING_GROQ_CREDENTIALS', 'UNSUPPORTED_GROQ_MODEL', 'INVALID_GROQ_CREDENTIALS', 'GROQ_RATE_LIMITED', 'GROQ_HTTP_ERROR', 'GROQ_UNAVAILABLE', 'PROMPT_TOO_LARGE'], true) ? $code : 'PROCESSING_FAILED';
    }

    public static function retryable(?string $code): bool
    {
        return in_array($code, ['JOB_FAILED', 'PROCESSING_FAILED', 'QUEUE_UNAVAILABLE', 'OCR_PROCESS_FAILED', 'OCR_TEMPORARY_STORAGE_FAILED', 'INVALID_AI_RESPONSE', 'OLLAMA_UNAVAILABLE', 'OLLAMA_HTTP_ERROR', 'GROQ_RATE_LIMITED', 'GROQ_HTTP_ERROR', 'GROQ_UNAVAILABLE'], true);
    }
}
