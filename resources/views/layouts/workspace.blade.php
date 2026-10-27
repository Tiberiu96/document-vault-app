<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $section === 'ai' ? __('ai.title') : __('documents.'.($section === 'dashboard' ? 'dashboard' : ($section === 'categories' ? 'categories' : 'title'))) }} — {{ config('app.name') }}</title>
    <meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body @class(['library-body', 'scanner-body' => ($section ?? '') === 'scan'])>
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
        @if(app()->environment('local') && config('ai.enabled') && $workspace && Gate::allows('manage', [App\Models\Document::class, $workspace]))<a href="{{ route('ai.playground') }}" @if($section === 'ai') aria-current="page" @endif><x-icon name="sparkle"/>{{ __('ai.title') }}</a>@endif
        </nav>
        <div class="library-botanical"><x-branch/><p>{{ __('site.life.quote') }}</p><span>{{ __('documents.private') }}</span></div>
        <a class="inline-link library-public-link" href="{{ route('home') }}">{{ config('app.name') }} ↗</a>
    </aside>
    <div class="library-main">
        <header class="library-topbar">
            @if($workspace)<x-workspace-picker :workspace="$workspace" :workspaces="$workspaces" class="library-mobile-workspace"/>@endif
            <a class="library-mobile-brand" href="{{ route('dashboard') }}">{{ config('app.name') }}<span>.</span></a>
            <form action="{{ route('documents.index') }}" class="library-search" role="search"><x-icon name="search"/><input type="search" name="q" maxlength="100" placeholder="{{ __('documents.search') }}" aria-label="{{ __('documents.search') }}" value="{{ is_string(request('q')) ? request('q') : '' }}"><button type="submit" aria-label="{{ __('documents.search') }}"><x-icon name="arrow"/></button></form>
            <details class="library-account"><summary><span class="library-avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span><span>{{ auth()->user()->name }}</span><x-icon name="chevron" :size="16"/></summary><div class="library-account-menu"><x-language-switcher :languages="$languages"/><a href="{{ route('verification.notice') }}">{{ __('account_mail.status') }}</a><form action="{{ route('logout') }}" method="POST">@csrf<button type="submit">{{ __('site.nav.logout') }}</button></form></div></details>
        </header>
        <main id="main" class="library-content" tabindex="-1">
            @if(session('library_status'))<div class="security-notice" role="status">{{ session('library_status') }}</div>@endif
            @if(session('security_status'))<div class="security-notice" role="status">{{ session('security_status') }}</div>@endif
            @if($errors->any())<div class="form-alert" role="alert">{{ $errors->first() }}</div>@endif
            @unless(auth()->user()->hasVerifiedEmail())<div class="security-notice"><x-icon name="shield"/><p>{{ __('account_mail.verify_banner') }}</p><a class="inline-link" href="{{ route('verification.notice') }}">{{ __('account_mail.verify_action') }}</a></div>@endunless
            @yield('content')
        </main>
    </div>
</div>
<nav class="library-mobile-nav" aria-label="{{ __('site.nav.menu') }}">
    <a href="{{ route('dashboard') }}"><x-icon name="home"/><span>{{ __('documents.home') }}</span></a>
    @if($workspace && Gate::allows('create', [App\Models\Document::class, $workspace]))<a class="mobile-upload mobile-scan" href="{{ route('documents.scan') }}"><x-icon name="scan"/><span>{{ __('scanner.short_title') }}</span></a>@endif
    <a href="{{ route('documents.index') }}"><x-icon name="document"/><span>{{ __('documents.title') }}</span></a>
</nav>
@stack('scripts')
</body></html>
