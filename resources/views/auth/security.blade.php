@extends('layouts.public')
@section('content')
<section class="auth-section wrap">
    <div class="auth-story"><span class="eyebrow">{{ __('account_mail.status') }}</span><h1>{{ __('account_mail.'.(in_array($mode, ['verify', 'verified']) ? 'verify_title' : 'reset_title')) }}</h1><p>{{ __('account_mail.security_description') }}</p><div class="auth-illustration"><x-branch/><span class="auth-lock"><x-icon name="shield" :size="36"/></span><p>{{ __('site.life.quote') }}</p></div></div>
    <div class="auth-card"><span class="eyebrow">{{ config('app.name') }}</span><h2>{{ __('account_mail.'.match($mode) { 'verify' => 'verify_page_title', 'verified' => 'verified', 'forgot' => 'forgot_title', default => 'reset_action' }) }}</h2>
        @if(!in_array($mode, ['reset', 'verified']))<p>{{ __('account_mail.'.($mode === 'verify' ? 'verify_page_intro' : 'forgot_intro')) }}</p>@endif
        @if(isset($securityNotice))<div class="security-notice" role="status">{{ $securityNotice }}</div>@endif
        @if(session('security_status'))<div class="security-notice" role="status">{{ session('security_status') }}</div>@endif
        @if($errors->any())<div class="form-alert" role="alert">{{ $errors->first() }}</div>@endif
        @if($mode === 'verified')
            <p>{{ __('account_mail.verified_hint') }}</p>
            @auth<form method="POST" action="{{ route('logout') }}">@csrf<input type="hidden" name="switch_account" value="1"><x-button type="submit">{{ __('account_mail.switch_account') }}</x-button></form>@else<x-button :href="route('login')">{{ __('site.nav.login') }}</x-button>@endauth
        @elseif($mode === 'verify')
            <form action="{{ route('verification.send') }}" method="POST" class="auth-form" data-resend-form data-seconds="{{ $resendAvailableIn ?? 0 }}" data-label="{{ __('account_mail.resend') }}" data-countdown="{{ __('account_mail.resend_countdown', ['seconds' => '{seconds}']) }}" data-minutes="{{ __('account_mail.resend_countdown_minutes', ['minutes' => '{minutes}', 'seconds' => '{seconds}']) }}">@csrf<x-button type="submit" icon="arrow" :disabled="($resendAvailableIn ?? 0) > 0"><span data-resend-label>{{ ($resendAvailableIn ?? 0) > 0 ? __('account_mail.resend_countdown', ['seconds' => $resendAvailableIn]) : __('account_mail.resend') }}</span></x-button></form>
            <p class="auth-help"><a class="action-button" href="{{ route('dashboard') }}" data-action="back"><x-icon name="back" :size="18"/>{{ __('account_mail.back_account') }}</a></p>
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
            <p class="auth-help"><a class="action-button" href="{{ route('login') }}" data-action="back"><x-icon name="back" :size="18"/>{{ __('account_mail.back_login') }}</a></p>
            @if($mode === 'reset')<a class="action-button" href="{{ route('password.request') }}">{{ __('account_mail.forgot_action') }}</a>@endif
        @endif
    </div>
</section>
@endsection

@push('scripts')<script src="{{ asset('js/verification-resend.js') }}" defer></script>@endpush
