@extends('layouts.public')
@section('content')
<section class="auth-section wrap">
    <div class="auth-story"><span class="eyebrow">{{ config('app.name') }}</span><h1>{{ __('auth.welcome') }}</h1><p>{{ __('auth.intro') }}</p><div class="auth-illustration"><x-branch/><span class="auth-lock"><x-icon name="shield" :size="36"/></span><p>{{ __('site.life.quote') }}</p></div></div>
    <div class="auth-card"><span class="eyebrow">{{ __('auth.your_space') }}</span><h2>{{ __('site.nav.login') }}</h2><p>{{ __('auth.form_intro') }}</p>
        @if(session('security_status'))<div class="security-notice" role="status">{{ session('security_status') }}</div>@endif
        @if($errors->any() || isset($loginError))<div class="form-alert" role="alert">{{ $loginError ?? $errors->first() }}</div>@endif
        <form action="{{ route('login.store') }}" method="POST" class="auth-form">
            @csrf
            <div><label for="email">{{ __('auth.email') }}</label><input id="email" name="email" type="email" autocomplete="username" required maxlength="255" value="{{ is_string(old('email')) ? old('email') : '' }}" @if($errors->has('email')) aria-invalid="true" @endif></div>
            <div><label for="password">{{ __('auth.password') }}</label><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="256"></div>
            <a class="action-button" href="{{ route('password.request') }}">{{ __('account_mail.forgot_title') }}</a>
            <label class="remember"><input type="checkbox" name="remember" value="1">{{ __('auth.remember') }}</label>
            <x-turnstile action="login"/>
            <x-button type="submit" icon="arrow">{{ __('site.nav.login') }}</x-button>
        </form>
        <p class="auth-help">{{ __('registration.no_account') }} <a class="action-button" href="{{ route('register') }}">{{ __('registration.create') }}</a></p><a class="action-button" href="{{ route('demo') }}">{{ __('site.nav.demo') }} <x-icon name="arrow" :size="16"/></a>
    </div>
</section>
@endsection
