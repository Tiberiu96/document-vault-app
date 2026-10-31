<?php

namespace App\Jobs\Billing;

use App\Models\StripeWebhookEvent;
use App\Services\Billing\ProcessStripeEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessStripeWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public int $eventId)
    {
        $this->onQueue('high');
    }

    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function handle(ProcessStripeEvent $processor): void
    {
        $processor->handle(StripeWebhookEvent::findOrFail($this->eventId));
    }

    public function failed(?\Throwable $exception): void
    {
        StripeWebhookEvent::whereKey($this->eventId)->update([
            'status' => 'failed',
            'last_error_code' => 'WEBHOOK_PROCESSING_FAILED',
        ]);
    }
}
