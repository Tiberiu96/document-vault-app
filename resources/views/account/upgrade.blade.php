@php
    $paid = $usage['plan'] === 'pro';
    $business = $workspace->type === 'business';
    $targetName = $business ? 'Business' : 'Pro';
    $canUpgrade = !$paid || ($business && $workspace->additional_member_seats < 1000);
@endphp
<section class="library-panel plan-upgrade" aria-labelledby="upgrade-title">
    <div class="plan-upgrade-heading"><span class="feature-icon"><x-icon name="{{ $business ? 'folder' : 'sparkle' }}" :size="26"/></span><div><span class="eyebrow">{{ __('plans.upgrade_kicker') }}</span><h2 id="upgrade-title">{{ $canUpgrade ? ($paid ? __('plans.upgrade_seats') : __('plans.upgrade_to', ['plan' => $targetName])) : __('plans.highest_plan') }}</h2></div><span class="document-chip">{{ __('plans.current', ['plan' => $paid ? $targetName : 'Free']) }}</span></div>
    @if($upgradeRequest)
        <div class="security-notice" role="status"><x-icon name="clock"/><div><strong>{{ __('plans.upgrade_pending') }}</strong><p>{{ __('plans.upgrade_pending_hint') }}</p>@if($business)<p>{{ __('plans.requested_seats', ['count' => $upgradeRequest->additional_member_seats]) }}</p>@endif</div></div>
    @elseif($canUpgrade && config('services.stripe.enabled') && $billingQuote && !$workspace->billingSubscription)
        <p>{{ __('plans.checkout_hint', ['plan' => $targetName]) }}</p>
        @can('requestUpgrade', $workspace)
        <a class="button" href="{{ route('billing.summary', ['currency' => $billingCurrency, 'additional_member_seats' => 0]) }}">{{ __('plans.checkout_price', ['price' => $billingPrices->format($billingQuote['plan_amount_minor'], $billingCurrency)]) }}</a>
        <p class="field-help">{{ __('plans.checkout_redirect') }}</p>
        @else<p class="field-help">{{ __('plans.upgrade_owner') }}</p>@endcan
    @elseif($workspace->billingSubscription)
        <p>{{ __('plans.subscription_status', ['status' => $workspace->billingSubscription->status]) }}</p>
        <p class="field-help">{{ __('plans.subscription_changes_later') }}</p>
    @elseif($canUpgrade)
        <p>{{ $paid ? __('plans.upgrade_seats_hint') : __('plans.upgrade_hint', ['plan' => $targetName]) }}</p>
        @if($business)<p>{{ __('plans.upgrade_business') }}</p>@endif
        @can('requestUpgrade', $workspace)
        <form action="{{ route('usage.upgrade') }}" method="POST" class="plan-upgrade-form">
            @csrf
            @if($business)<label for="upgrade-seats">{{ __('plans.additional_seats') }}<input id="upgrade-seats" name="additional_member_seats" type="number" min="{{ $paid ? $workspace->additional_member_seats + 1 : 0 }}" max="1000" value="{{ old('additional_member_seats', $paid ? $workspace->additional_member_seats + 1 : 0) }}" required><span class="field-help">{{ __('plans.additional_seats_hint') }}</span></label>@endif
            <x-button type="submit" icon="arrow">{{ $paid ? __('plans.request_seats') : __('plans.request_upgrade') }}</x-button>
        </form>
        <p class="field-help">{{ __('plans.upgrade_manual') }}</p>
        @else<p class="field-help">{{ __('plans.upgrade_owner') }}</p>@endcan
    @else<p>{{ __('plans.highest_plan_hint') }}</p>@endif
</section>
