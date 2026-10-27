@extends('layouts.public')
@section('content')
<section class="auth-section wrap">
    <div class="auth-story"><span class="eyebrow">{{ __('account_mail.status') }}</span><h1>{{ __('account_mail.'.($mode === 'verify' ? 'verify_title' : 'reset_title')) }}</h1><p>{{ __('account_mail.security_description') }}</p><div class="auth-illustration"><x-branch/><span class="auth-lock"><x-icon name="shield" :size="36"/></span><p>{{ __('site.life.quote') }}</p></div></div>
    <div class="auth-card"><span class="eyebrow">{{ config('app.name') }}</span><h2>{{ __('account_mail.'.match($mode) { 'verify' => 'verify_page_title', 'forgot' => 'forgot_title', default => 'reset_action' }) }}</h2>
        @if($mode !== 'reset')<p>{{ __('account_mail.'.($mode === 'verify' ? 'verify_page_intro' : 'forgot_intro')) }}</p>@endif
        @if(session('security_status'))<div class="security-notice" role="status">{{ session('security_status') }}</div>@endif
        @if($errors->any())<div class="form-alert" role="alert">{{ $errors->first() }}</div>@endif
        @if($mode === 'verify')
            <form action="{{ route('verification.send') }}" method="POST" class="auth-form">@csrf<x-button type="submit" icon="arrow">{{ __('account_mail.resend') }}</x-button></form>
            <p class="auth-help"><a class="inline-link" href="{{ route('dashboard') }}">{{ __('account_mail.back_account') }}</a></p>
        @else
            <form action="{{ route($mode === 'forgot' ? 'password.email' : 'password.update') }}" method="POST" class="auth-form">
                @csrf
                @if($mode === 'reset')<input type="hidden" name="token" value="{{ request()->route('token') }}">@endif
                <div><label for="email">{{ __('auth.email') }}</label><input id="email" name="email" type="email" autocomplete="username" required maxlength="255" value="{{ is_string(old('email', request('email'))) ? old('email', request('email')) : '' }}"></div>
                @if($mode === 'reset')
                    <div><label for="password">{{ __('account_mail.new_password') }}</label><input id="password" name="password" type="password" autocomplete="new-password" required minlength="16" maxlength="72" aria-describedby="password-help"><p id="password-help" class="field-help">{{ __('account_mail.password_rules') }}</p></div>
                    <div><label for="password_confirmation">{{ __('account_mail.confirm_password') }}</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required minlength="16" maxlength="72"></div>
                @endif
                <x-button type="submit" icon="arrow">{{ __('account_mail.'.($mode === 'forgot' ? 'forgot_action' : 'reset_action')) }}</x-button>
            </form>
            <p class="auth-help"><a class="inline-link" href="{{ route('login') }}">{{ __('account_mail.back_login') }}</a></p>
            @if($mode === 'reset')<a class="text-link" href="{{ route('password.request') }}">{{ __('account_mail.forgot_action') }}</a>@endif
        @endif
    </div>
</section>
@endsection
