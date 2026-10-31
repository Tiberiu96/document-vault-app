<?php

namespace App\Http\Controllers;

use App\Jobs\Billing\ProcessStripeWebhook;
use App\Models\StripeWebhookEvent;
use App\Services\Billing\StripeWebhookVerifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhookVerifier $verifier)
    {
        $payload = $request->getContent();
        try {
            $event = $verifier->verify($payload, (string) $request->header('Stripe-Signature'));
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response()->json(['code' => 'invalid_signature'], 400);
        }
        if ((bool) $event->livemode !== (config('services.stripe.environment') === 'live')) {
            return response()->json(['code' => 'invalid_environment'], 400);
        }

        try {
            $stored = StripeWebhookEvent::create([
                'environment' => config('services.stripe.environment'),
                'stripe_event_id' => $event->id,
                'event_type' => $event->type,
                'provider_created_at' => (int) $event->created,
                'payload' => json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
                'status' => 'queued',
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['received' => true]);
        }

        ProcessStripeWebhook::dispatch($stored->id)->afterCommit();

        return response()->json(['received' => true]);
    }
}
