<?php

namespace App\Services\Billing;

use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookVerifier
{
    public function verify(string $payload, string $signature): Event
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if (! config('services.stripe.enabled') || $secret === '') {
            throw new SignatureVerificationException('Stripe webhook is not configured.', $signature);
        }

        return Webhook::constructEvent($payload, $signature, $secret, (int) config('services.stripe.webhook_tolerance', 300));
    }
}
