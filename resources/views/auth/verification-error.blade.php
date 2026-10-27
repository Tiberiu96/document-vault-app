@extends('layouts.public')
@section('content')
<section class="auth-section wrap">
    <div class="auth-story"><span class="eyebrow">{{ __('account_mail.status') }}</span><h1>{{ __('account_mail.verification_problem') }}</h1><p>{{ __('account_mail.'.$verificationProblem) }}</p><div class="auth-illustration"><x-branch/><span class="auth-lock"><x-icon name="shield" :size="36"/></span></div></div>
    <div class="auth-card"><h2>{{ __('account_mail.verify_page_title') }}</h2><p>{{ __('account_mail.expired_help') }}</p><div class="auth-form">
        @auth
        <x-button :href="route('verification.notice')" variant="outline">{{ __('account_mail.resend') }}</x-button>
        <form method="POST" action="{{ route('logout') }}">@csrf<input type="hidden" name="switch_account" value="1"><x-button type="submit">{{ __('account_mail.switch_account') }}</x-button></form>
        @else<x-button :href="route('login')">{{ __('site.nav.login') }}</x-button>@endauth
    </div></div>
</section>
@endsection
