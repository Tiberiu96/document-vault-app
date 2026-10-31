@extends('layouts.workspace')
@section('content')
<div class="library-heading"><div><span class="eyebrow">{{ $workspace->name }}</span><h1>{{ __('plans.title') }}</h1><p>{{ __('plans.current', ['plan'=>$usage['plan'] === 'pro' && $workspace->type === 'business' ? 'Business' : ucfirst($usage['plan'])]) }}</p></div><a class="action-button" href="{{ route('dashboard') }}"><x-icon name="back"/>{{ __('documents.dashboard') }}</a></div>
@include('account.upgrade')
@include('account.usage-metrics')
<section class="library-panel usage-details"><h2>{{ __('plans.details') }}</h2><p>{{ app(\App\Services\PlanLimitsService::class)->message($workspace) }}</p><p>{{ __('plans.shared') }}</p><p>{{ __('plans.reset', ['date'=>$usage['resets_at']]) }}</p>@if($usage['expires_at'])<p>{{ __('plans.expires', ['date'=>$usage['expires_at']->timezone('UTC')->format('d.m.Y H:i')]) }}</p>@endif<p class="field-help">{{ __('plans.accounting') }}</p>@unless(config('services.stripe.enabled'))<p class="field-help">{{ __('plans.payments_later') }}</p>@endunless</section>
@endsection
