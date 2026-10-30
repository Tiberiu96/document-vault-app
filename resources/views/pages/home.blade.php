@extends('layouts.public')
@section('content')
@php($scanUrl = route('documents.scan'))
<section class="hero home-hero wrap" aria-labelledby="hero-title">
    <div class="hero-copy">
        <a class="hero-kicker" href="{{ route('features') }}"><x-icon name="sparkle" :size="20"/><span>{{ __('site.hero.kicker') }}</span><x-icon name="arrow" :size="18"/></a>
        <h1 id="hero-title">{{ __('site.hero.line1') }}<br>{{ __('site.hero.line2') }}<br><em>{{ __('site.hero.line3') }}</em></h1>
        <p class="hero-description">{{ __('site.hero.description') }}</p>
        <div class="hero-actions">
            <x-button :href="$scanUrl" icon="arrow"><x-icon name="camera" :size="22"/>{{ __('site.hero.cta') }}</x-button>
            <x-button href="#features" variant="outline"><x-icon name="play" :size="28"/>{{ __('site.hero.secondary') }}</x-button>
        </div>
        <p class="hero-note"><x-icon name="lock" :size="20"/>{{ __('site.hero.note') }}</p>
        <ul class="hero-trust" role="list">@foreach(['private', 'intelligent', 'together'] as $benefit)<li><x-icon name="circle-check" :size="18"/>{{ __('site.hero.'.$benefit) }}</li>@endforeach</ul>
    </div>
    <div class="hero-visual">
        <div class="hero-branch"><x-branch/></div>
        @include('partials.product-preview')
        <svg class="scan-arrow" viewBox="0 0 50 100" fill="none" aria-hidden="true"><path d="M20 4C2 40 9 64 39 82m-17-2 19 5-6-18" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <div class="hero-scan-card">
            <div class="scan-phone" aria-hidden="true"><div class="scan-phone-screen"><div class="scan-receipt"><strong>{{ __('site.hero.invoice') }}</strong><span></span><span></span><span></span><div class="receipt-lines"></div><span></span><span></span></div><div class="scan-frame"><i></i><i></i><i></i><i></i></div></div></div>
            <div class="scan-card-copy"><span class="scan-card-kicker">{{ __('site.hero.scan_kicker') }}</span><h2>{{ __('site.hero.scan_title') }}</h2><p>{{ __('site.hero.scan_text') }}</p><x-button :href="$scanUrl" icon="arrow"><x-icon name="camera" :size="20"/>{{ __('site.hero.scan_title') }}</x-button></div>
        </div>
    </div>
</section>
@include('partials.features')
<section class="life-section wrap"><div class="life-art"><span class="eyebrow">{{ config('app.name') }} / {{ __('site.common.personal') }}</span><h2>{!! nl2br(e(__('site.life.quote'))) !!}</h2><x-branch/><div class="life-art-bottom"><x-icon name="folder"/><span>{{ __('site.life.personal') }}</span></div></div><div class="life-copy"><span class="eyebrow">{{ __('site.life.kicker') }}</span><h2>{!! nl2br(e(__('site.life.title'))) !!}</h2><p>{{ __('site.life.text') }}</p><div class="life-points"><span><x-icon name="check" :size="16"/>{{ __('site.life.personal') }}</span><span><x-icon name="check" :size="16"/>{{ __('site.life.business') }}</span></div><a class="action-button" href="{{ route('features') }}">{{ __('site.nav.features') }} <x-icon name="arrow" :size="17"/></a></div></section>
@include('partials.pricing')
@include('partials.faq')
@endsection
