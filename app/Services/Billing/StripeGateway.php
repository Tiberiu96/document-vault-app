<?php

namespace App\Services\Billing;

use App\Contracts\Billing\StripeBillingGateway;
use Stripe\StripeClient;

class StripeGateway implements StripeBillingGateway
{
    public function createSubscriptionCheckout(array $parameters, string $idempotencyKey): array
    {
        $secret = (string) config('services.stripe.secret');
        if (! config('services.stripe.enabled') || $secret === '') {
            throw new BillingConfigurationException('STRIPE_DISABLED');
        }

        $session = (new StripeClient($secret))->checkout->sessions->create($parameters, ['idempotency_key' => $idempotencyKey]);

        if (! is_string($session->url) || $session->url === '') {
            throw new BillingConfigurationException('STRIPE_CHECKOUT_URL_MISSING');
        }

        return ['id' => $session->id, 'url' => $session->url, 'expires_at' => (int) $session->expires_at];
    }
}
