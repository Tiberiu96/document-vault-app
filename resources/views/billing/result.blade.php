@extends('layouts.workspace')
@section('content')
<div class="library-heading"><div><span class="eyebrow">{{ $workspace->name }}</span><h1>{{ __('plans.checkout_result') }}</h1></div><a class="action-button" href="{{ route('usage') }}"><x-icon name="back"/>{{ __('plans.return_usage') }}</a></div>
<section class="library-panel billing-result">@if($attempt->status === 'completed' && $subscription?->grantsEntitlement())<h2>{{ __('plans.subscription_active') }}</h2><p>{{ __('plans.subscription_active_hint') }}</p>@elseif(request('status') === 'cancel')<h2>{{ __('plans.checkout_cancelled') }}</h2><p>{{ __('plans.checkout_cancelled_hint') }}</p>@else<h2>{{ __('plans.checkout_processing') }}</h2><p>{{ __('plans.checkout_processing_hint') }}</p>@endif</section>
@endsection
