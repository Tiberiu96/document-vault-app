<?php

namespace App\Contracts\Billing;

interface StripeBillingGateway
{
    /** @return array{id: string, url: string, expires_at: int} */
    public function createSubscriptionCheckout(array $parameters, string $idempotencyKey): array;
}
