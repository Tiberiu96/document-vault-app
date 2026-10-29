@extends('layouts.public')
@section('content')
<section class="hero wrap">
    <div class="hero-copy"><span class="eyebrow">{{ __('site.hero.kicker') }}</span><h1>{{ __('site.hero.line1') }}<br>{{ __('site.hero.line2') }}<br><em>{{ __('site.hero.line3') }}</em></h1><p class="hero-description">{{ __('site.hero.description') }}</p>
        <div class="hero-actions"><x-button :href="auth()->check() ? route('dashboard') : route('register')" icon="arrow">{{ auth()->check() ? __('site.nav.account') : __('registration.create') }}</x-button></div>
        <p class="hero-note"><span class="live-dot"></span>{{ __('site.hero.note') }}</p>
    </div>
    <div class="hero-visual"><div class="visual-label"><span>01 / {{ __('site.common.preview') }}</span><span>{{ __('site.preview_ui.example') }}</span></div>@include('partials.product-preview')<div class="floating-note"><span class="floating-icon"><x-icon name="check" :size="19"/></span><div>{{ __('site.footer.line') }}<small>{{ __('site.common.preview') }}</small></div></div></div>
</section>
@include('partials.features')
<section class="life-section wrap"><div class="life-art"><span class="eyebrow">{{ config('app.name') }} / {{ __('site.common.personal') }}</span><h2>{!! nl2br(e(__('site.life.quote'))) !!}</h2><x-branch/><div class="life-art-bottom"><x-icon name="folder"/><span>{{ __('site.life.personal') }}</span></div></div><div class="life-copy"><span class="eyebrow">{{ __('site.life.kicker') }}</span><h2>{!! nl2br(e(__('site.life.title'))) !!}</h2><p>{{ __('site.life.text') }}</p><div class="life-points"><span><x-icon name="check" :size="16"/>{{ __('site.life.personal') }}</span><span><x-icon name="check" :size="16"/>{{ __('site.life.business') }}</span></div><a class="action-button" href="{{ route('features') }}">{{ __('site.nav.features') }} <x-icon name="arrow" :size="17"/></a></div></section>
@include('partials.pricing')
@include('partials.faq')
@endsection
