<?php

namespace App\Services\Billing;

use App\Models\BillingCheckoutAttempt;
use App\Models\StripeWebhookEvent;
use App\Models\Workspace;
use App\Models\WorkspaceBillingSubscription;
use Illuminate\Support\Facades\DB;

class ProcessStripeEvent
{
    public function handle(StripeWebhookEvent $stored): void
    {
        DB::transaction(function () use ($stored): void {
            $stored = StripeWebhookEvent::lockForUpdate()->findOrFail($stored->id);
            if ($stored->status === 'processed') {
                return;
            }

            $stored->forceFill(['status' => 'processing', 'attempts' => $stored->attempts + 1, 'last_error_code' => null])->save();
            $payload = $stored->payload;
            $object = $payload['data']['object'] ?? [];

            match ($stored->event_type) {
                'checkout.session.completed' => $this->checkoutCompleted($stored, $object),
                'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted' => $this->subscriptionChanged($stored, $object),
                'invoice.paid' => $this->invoiceState($stored, $object, 'active'),
                'invoice.payment_failed' => $this->invoiceState($stored, $object, 'past_due'),
                default => null,
            };

            $stored->forceFill(['status' => 'processed', 'processed_at' => now(), 'last_error_code' => null])->save();
        }, 3);
    }

    private function checkoutCompleted(StripeWebhookEvent $event, array $session): void
    {
        $attemptId = $session['metadata']['checkout_attempt_id'] ?? null;
        if (! is_string($attemptId)) {
            return;
        }
        $attempt = BillingCheckoutAttempt::lockForUpdate()->find($attemptId);
        if (! $attempt || $attempt->provider_session_id !== ($session['id'] ?? null)) {
            throw new \UnexpectedValueException('CHECKOUT_ATTEMPT_MISMATCH');
        }
        if (strtoupper((string) ($session['currency'] ?? '')) !== $attempt->currency || (int) ($session['amount_total'] ?? -1) !== $attempt->total_amount_minor) {
            throw new \UnexpectedValueException('CHECKOUT_AMOUNT_MISMATCH');
        }
        $attempt->forceFill(['status' => 'completed'])->save();
        $status = in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true) ? 'active' : 'incomplete';
        $this->applySubscription($event, $attempt->workspace_id, [
            'customer' => $session['customer'] ?? null,
            'id' => $session['subscription'] ?? null,
            'status' => $status,
            'currency' => $attempt->currency,
            'additional_member_seats' => $attempt->additional_member_seats,
        ]);
    }

    private function subscriptionChanged(StripeWebhookEvent $event, array $subscription): void
    {
        $workspaceId = $subscription['metadata']['workspace_id'] ?? null;
        if (! $workspaceId && isset($subscription['id'])) {
            $workspaceId = WorkspaceBillingSubscription::where('provider_subscription_id', $subscription['id'])->value('workspace_id');
        }
        if (! $workspaceId) {
            return;
        }

        $currency = strtoupper((string) ($subscription['currency'] ?? ''));
        $seats = max(0, (int) ($subscription['metadata']['additional_member_seats'] ?? 0));
        $this->applySubscription($event, (int) $workspaceId, [
            'customer' => $subscription['customer'] ?? null,
            'id' => $subscription['id'] ?? null,
            'status' => $event->event_type === 'customer.subscription.deleted' ? 'canceled' : ($subscription['status'] ?? 'incomplete'),
            'currency' => $currency,
            'additional_member_seats' => $seats,
            'current_period_end' => $subscription['current_period_end'] ?? null,
            'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
        ]);
    }

    private function invoiceState(StripeWebhookEvent $event, array $invoice, string $status): void
    {
        $subscriptionId = $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? null;
        if (! is_string($subscriptionId)) {
            return;
        }
        $subscription = WorkspaceBillingSubscription::where('provider_subscription_id', $subscriptionId)->first();
        if ($subscription) {
            $this->applySubscription($event, $subscription->workspace_id, [
                'customer' => $subscription->provider_customer_id,
                'id' => $subscriptionId,
                'status' => $status,
                'currency' => $subscription->currency,
                'additional_member_seats' => $subscription->additional_member_seats,
            ]);
        }
    }

    private function applySubscription(StripeWebhookEvent $event, int $workspaceId, array $state): void
    {
        $workspace = Workspace::lockForUpdate()->findOrFail($workspaceId);
        $subscription = WorkspaceBillingSubscription::lockForUpdate()->firstOrNew(['workspace_id' => $workspace->id]);
        if ($event->provider_created_at < (int) $subscription->provider_event_created_at) {
            return;
        }

        $status = (string) ($state['status'] ?? 'incomplete');
        $seats = $workspace->type === 'business' ? min(1000, max(0, (int) ($state['additional_member_seats'] ?? 0))) : 0;
        $subscription->fill([
            'provider' => 'stripe', 'environment' => $event->environment,
            'provider_customer_id' => $state['customer'] ?? $subscription->provider_customer_id,
            'provider_subscription_id' => $state['id'] ?? $subscription->provider_subscription_id,
            'status' => $status, 'currency' => $state['currency'] ?: $subscription->currency,
            'additional_member_seats' => $seats,
            'current_period_end' => isset($state['current_period_end']) ? date('Y-m-d H:i:s', (int) $state['current_period_end']) : $subscription->current_period_end,
            'cancel_at_period_end' => (bool) ($state['cancel_at_period_end'] ?? $subscription->cancel_at_period_end),
            'provider_event_created_at' => $event->provider_created_at,
        ])->save();

        $active = in_array($status, ['active', 'trialing'], true);
        $workspace->forceFill([
            'plan_code' => $active ? 'pro' : 'free',
            'additional_member_seats' => $active ? $seats : 0,
            'plan_expires_at' => null,
            'plan_version' => $workspace->plan_version + 1,
        ])->save();
    }
}
