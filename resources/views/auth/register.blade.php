@extends('layouts.public')
@section('content')
@php($field = fn ($key, $fallback = '') => is_string(old($key)) ? old($key) : $fallback)
<section class="auth-section registration-section wrap">
    <div class="auth-story"><span class="eyebrow">{{ config('app.name') }}</span><h1>{{ __('registration.title') }}</h1><p>{{ __('registration.intro') }}</p><div class="auth-illustration"><x-branch/><span class="auth-lock"><x-icon name="folder" :size="36"/></span><p>{{ __('site.life.quote') }}</p></div><p class="registration-side-note">{{ __('registration.pj_note') }}</p></div>
    <div class="auth-card registration-card"><span class="eyebrow">{{ __('registration.kicker') }}</span><h2>{{ __('registration.create') }}</h2>
        @if(isset($registrationError))<div class="form-alert" role="alert">{{ $registrationError }}</div>@elseif($errors->any())<div class="form-alert" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form action="{{ route('register.store') }}" method="POST" class="auth-form registration-form">
            @csrf
            <fieldset class="account-types"><legend>{{ __('registration.account_type') }}</legend><label><input type="radio" name="account_type" id="account-pf" value="pf" @checked($field('account_type', 'pf') === 'pf') required><span><strong>{{ __('registration.pf') }}</strong><small>{{ __('registration.pf_hint') }}</small></span></label><label><input type="radio" name="account_type" id="account-pj" value="pj" @checked($field('account_type') === 'pj')><span><strong>{{ __('registration.pj') }}</strong><small>{{ __('registration.pj_hint') }}</small></span></label></fieldset>
            @foreach(['name', 'email'] as $key)<div><label for="{{ $key }}">{{ __('registration.'.$key) }}</label><input id="{{ $key }}" name="{{ $key }}" type="{{ $key === 'email' ? 'email' : 'text' }}" autocomplete="{{ $key === 'email' ? 'email' : 'name' }}" value="{{ $field($key) }}" maxlength="{{ $key === 'name' ? 120 : 255 }}" required @if($errors->has($key)) aria-invalid="true" @endif></div>@endforeach
            <fieldset class="company-fields"><legend>{{ __('registration.company_heading') }}</legend><p>{{ __('registration.company_hint') }}</p>
                @foreach(['company_name', 'cui', 'registration_number', 'county', 'city', 'street', 'postal_code'] as $key)<div><label for="{{ $key }}">{{ __('registration.'.$key) }}@if($key === 'postal_code') <small>({{ __('registration.optional') }})</small>@endif</label><input id="{{ $key }}" name="{{ $key }}" type="text" value="{{ $field($key) }}" maxlength="{{ ['company_name'=>200,'cui'=>12,'registration_number'=>64,'county'=>100,'city'=>100,'street'=>200,'postal_code'=>6][$key] }}" @if(in_array($key,['cui','postal_code'])) inputmode="{{ $key === 'postal_code' ? 'numeric' : 'text' }}" @endif @if($errors->has($key)) aria-invalid="true" @endif></div>@endforeach
                <small>{{ __('registration.registry_note') }}</small>
            </fieldset>
            <div><label for="password">{{ __('registration.password') }}</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required aria-describedby="password-hint"><small id="password-hint" class="field-hint">{{ __('registration.password_requirements') }}</small></div>
            <div><label for="password_confirmation">{{ __('registration.password_confirmation') }}</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></div>
            <div class="form-trap" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
            <x-button type="submit" icon="arrow">{{ __('registration.create') }}</x-button>
        </form>
        <p class="auth-help">{{ __('registration.have_account') }} <a class="inline-link" href="{{ route('login') }}">{{ __('site.nav.login') }}</a></p>
    </div>
</section>
@endsection
