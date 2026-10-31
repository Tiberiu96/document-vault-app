<?php

namespace App\Http\Controllers;

use App\Contracts\Billing\StripeBillingGateway;
use App\Http\Requests\BillingSummaryRequest;
use App\Models\BillingCheckoutAttempt;
use App\Services\Billing\BillingConfigurationException;
use App\Services\Billing\BillingPriceService;
use App\Services\WebsiteContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Stripe\Exception\ApiErrorException;

class BillingController extends Controller
{
    public function summary(BillingSummaryRequest $request, BillingPriceService $prices)
    {
        $workspace = $request->attributes->get('workspace');
        Gate::authorize('requestUpgrade', $workspace);
        $data = $request->validated();
        $currency = strtoupper((string) ($data['currency'] ?? $prices->defaultCurrency()));
        $seats = $workspace->type === 'business' ? (int) ($data['additional_member_seats'] ?? 0) : 0;
        $quote = $prices->quote($workspace, $currency, $seats);

        return view('billing.summary', [
            ...app(WebsiteContext::class)->forPage($request, 'usage'),
            'section' => 'usage',
            'workspace' => $workspace,
            'workspaces' => $request->user()->workspaces()->get()->filter(fn ($candidate) => Gate::allows('view', $candidate)),
            'quote' => $quote,
            'currencies' => $prices->enabledCurrencies($workspace),
            'prices' => $prices,
        ]);
    }

    public function checkout(BillingSummaryRequest $request, BillingPriceService $prices, StripeBillingGateway $stripe): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        Gate::authorize('requestUpgrade', $workspace);
        if ($workspace->billingSubscription()->whereIn('status', ['active', 'trialing', 'past_due', 'incomplete'])->exists()) {
            throw ValidationException::withMessages(['billing' => __('plans.billing_existing_subscription')]);
        }

        $data = $request->validated();
        $quote = $prices->quote($workspace, strtoupper((string) ($data['currency'] ?? '')), $workspace->type === 'business' ? (int) ($data['additional_member_seats'] ?? 0) : 0);
        $attempt = BillingCheckoutAttempt::create([
            ...$quote,
            'workspace_id' => $workspace->id,
            'requested_by' => $request->user()->id,
            'idempotency_key' => 'checkout_'.Str::uuid(),
            'status' => 'creating',
            'expires_at' => now()->addMinutes(30),
        ]);

        $lineItems = [[
            'quantity' => 1,
            'price_data' => [
                'currency' => strtolower($quote['currency']),
                'unit_amount' => $quote['plan_amount_minor'],
                'recurring' => ['interval' => 'month'],
                'product_data' => ['name' => $workspace->type === 'business' ? 'Vaulty Business' : 'Vaulty Pro'],
            ],
        ]];
        if ($quote['additional_member_seats'] > 0) {
            $lineItems[] = [
                'quantity' => $quote['additional_member_seats'],
                'price_data' => [
                    'currency' => strtolower($quote['currency']),
                    'unit_amount' => $quote['extra_seat_amount_minor'],
                    'recurring' => ['interval' => 'month'],
                    'product_data' => ['name' => 'Vaulty Business - loc suplimentar'],
                ],
            ];
        }

        try {
            $session = $stripe->createSubscriptionCheckout([
                'mode' => 'subscription',
                'customer_email' => $request->user()->email,
                'client_reference_id' => (string) $workspace->id,
                'line_items' => $lineItems,
                'metadata' => ['checkout_attempt_id' => $attempt->id, 'workspace_id' => (string) $workspace->id],
                'subscription_data' => ['metadata' => ['workspace_id' => (string) $workspace->id, 'additional_member_seats' => (string) $quote['additional_member_seats']]],
                'success_url' => route('billing.result', ['attempt' => $attempt]).'?status=success',
                'cancel_url' => route('billing.result', ['attempt' => $attempt]).'?status=cancel',
                'locale' => in_array(app()->getLocale(), ['ro', 'en', 'hu', 'bg'], true) ? app()->getLocale() : 'auto',
            ], $attempt->idempotency_key);
        } catch (BillingConfigurationException|ApiErrorException) {
            $attempt->forceFill(['status' => 'failed'])->save();
            throw ValidationException::withMessages(['billing' => __('plans.billing_unavailable')]);
        }

        $attempt->forceFill(['provider_session_id' => $session['id'], 'status' => 'pending', 'expires_at' => date('Y-m-d H:i:s', $session['expires_at'])])->save();

        return redirect()->away($session['url']);
    }

    public function result(BillingSummaryRequest $request, BillingCheckoutAttempt $attempt)
    {
        $workspace = $request->attributes->get('workspace');
        Gate::authorize('requestUpgrade', $workspace);
        abort_unless($attempt->workspace_id === $workspace->id && $attempt->requested_by === $request->user()->id, 404);

        return view('billing.result', [
            ...app(WebsiteContext::class)->forPage($request, 'usage'),
            'section' => 'usage',
            'workspace' => $workspace,
            'workspaces' => $request->user()->workspaces()->get()->filter(fn ($candidate) => Gate::allows('view', $candidate)),
            'attempt' => $attempt,
            'subscription' => $workspace->billingSubscription,
        ]);
    }
}
