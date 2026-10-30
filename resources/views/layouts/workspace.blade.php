<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ match($section) { 'profile' => __('profile.title'), 'usage' => __('plans.title'), 'team' => __('team.title'), 'invitations' => __('team.incoming_title'), 'notifications' => __('notifications.title'), 'reminders' => __('reminders.title'), 'ai' => __('ai.title'), 'dashboard' => __('documents.dashboard'), 'categories' => __('documents.categories'), default => __('documents.title') } }} — {{ config('app.name') }}</title>
    <meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">
    @livewireStyles
    <x-favicons/>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body @class(['library-body', 'scanner-body' => ($section ?? '') === 'scan', 'upload-body' => ($section ?? '') === 'upload'])>
@include('admin.partials.impersonation-banner')
<a href="#main" class="skip-link">{{ __('site.a11y.skip') }}</a>
<div class="library-shell">
    <aside class="library-sidebar">
        <x-brand/>
        @if($workspace)
        <x-workspace-picker :workspace="$workspace" :workspaces="$workspaces"/>
        @endif
        <nav class="library-nav" aria-label="{{ __('site.nav.menu') }}">
            @foreach(['dashboard' => ['home', 'dashboard'], 'documents.index' => ['document', 'title'], 'categories.index' => ['folder', 'categories']] as $name => [$icon, $label])
            <a href="{{ route($name) }}" @if(($name === 'dashboard' && $section === 'dashboard') || ($name === 'documents.index' && in_array($section, ['documents', 'upload'])) || ($name === 'categories.index' && $section === 'categories')) aria-current="page" @endif><x-icon :name="$icon"/>{{ __('documents.'.$label) }}</a>
            @endforeach
            <a href="{{ route('reminders.index') }}" @if($section === 'reminders')aria-current="page"@endif><x-icon name="bell"/>{{ __('reminders.title') }}</a>
            @if($workspace?->type === 'business')<a href="{{ route('members.index') }}" @if($section === 'team')aria-current="page"@endif><x-icon name="folder"/>{{ __('team.title') }}</a>@endif
            <a href="{{ route('usage') }}" @if($section === 'usage')aria-current="page"@endif><x-icon name="database"/>{{ __('plans.title') }}</a>
            <a href="{{ route('invitations.index') }}" @if($section === 'invitations')aria-current="page"@endif><x-icon name="bell"/>{{ __('team.incoming_title') }}</a>
        @if(app()->environment('local') && config('ai.enabled') && $workspace && Gate::allows('manage', [App\Models\Document::class, $workspace]))<a href="{{ route('ai.playground') }}" @if($section === 'ai') aria-current="page" @endif><x-icon name="sparkle"/>{{ __('ai.title') }}</a>@endif
        </nav>
        <a class="profile-nav-link" href="{{ route('profile.edit') }}" @if($section === 'profile') aria-current="page" @endif><x-user-avatar/>{{ __('profile.title') }}</a>
        <div class="library-botanical"><x-branch/><p>{{ __('site.life.quote') }}</p><span>{{ __('documents.private') }}</span></div>
        <a class="action-button library-public-link" href="{{ route('home') }}">{{ config('app.name') }} ↗</a>
    </aside>
    <div class="library-main">
        <header class="library-topbar">
            @if($workspace)<x-workspace-picker :workspace="$workspace" :workspaces="$workspaces" class="library-mobile-workspace"/>@endif
            <x-brand class="library-mobile-brand" :href="route('dashboard')"/>
            <form action="{{ route('documents.index') }}" class="library-search" role="search"><x-icon name="search"/><input type="search" name="q" maxlength="100" placeholder="{{ __('documents.search') }}" aria-label="{{ __('documents.search') }}" value="{{ is_string(request('q')) ? request('q') : '' }}"><button type="submit" aria-label="{{ __('documents.search') }}"><x-icon name="arrow"/></button></form>
            @if($workspace && auth()->user()->hasVerifiedEmail())<livewire:notification-bell :workspace-id="$workspace->id" :key="'bell-'.$workspace->id"/>@endif
            <details class="library-account"><summary><x-user-avatar class="library-avatar"/><span>{{ auth()->user()->name }}</span><x-icon name="chevron" :size="16"/></summary><div class="library-account-menu"><a href="{{ route('profile.edit') }}">{{ __('profile.title') }}</a><a href="{{ route('usage') }}">{{ __('plans.title') }}</a><a href="{{ route('invitations.index') }}">{{ __('team.incoming_title') }}</a>@if($workspace?->type === 'business')<a href="{{ route('members.index') }}">{{ __('team.title') }}</a>@endif@if(auth()->user()->hasVerifiedEmail())<a href="{{ route('notifications.index') }}">{{ __('notifications.title') }}</a>@endif<a href="{{ route('reminders.index') }}">{{ __('reminders.title') }}</a><x-language-switcher :languages="$languages"/><a href="{{ route('verification.notice') }}">{{ __('account_mail.status') }}</a><form action="{{ route('logout') }}" method="POST">@csrf<button type="submit">{{ __('site.nav.logout') }}</button></form></div></details>
        </header>
        <main id="main" class="library-content" tabindex="-1">
            @if(session('library_status'))<div class="security-notice" role="status">{{ session('library_status') }}</div>@endif
            @if(session('security_status'))<div class="security-notice" role="status">{{ session('security_status') }}</div>@endif
            @if($errors->any())<div class="form-alert" role="alert">{{ $errors->first() }}</div>@endif
            @unless(auth()->user()->hasVerifiedEmail())<div class="security-notice"><x-icon name="shield"/><p>{{ __('account_mail.verify_banner') }}</p><a class="action-button" href="{{ route('verification.notice') }}">{{ __('account_mail.verify_action') }}</a></div>@endunless
            @yield('content')
        </main>
    </div>
</div>
<nav class="library-mobile-nav" aria-label="{{ __('site.nav.menu') }}">
    <a href="{{ route('dashboard') }}"><x-icon name="home"/><span>{{ __('documents.home') }}</span></a>
    @if($workspace && Gate::allows('create', [App\Models\Document::class, $workspace]))<a class="mobile-upload mobile-scan" href="{{ route('documents.scan') }}"><x-icon name="scan"/><span>{{ __('scanner.short_title') }}</span></a>@endif
    <a href="{{ route('documents.index') }}"><x-icon name="document"/><span>{{ __('documents.title') }}</span></a>
</nav>
<template id="processing-loading-template">@include('documents.processing-loading', ['loadingDocument' => null])</template>
<script src="{{ asset('js/document-upload-flow.js') }}?v={{ filemtime(public_path('js/document-upload-flow.js')) }}" defer></script>
@stack('scripts')
@livewireScripts
</body></html>
