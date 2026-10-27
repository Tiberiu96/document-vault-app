<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        $brand = config('app.name');
        $title = __('site.seo.'.$page.'.title').' — '.$brand;
        $description = __('site.seo.'.$page.'.description');
        $slug = __('routes.'.$page);
        $canonical = rtrim(config('app.url'), '/').'/'.app()->getLocale().($slug ? '/'.$slug : '');
        $canIndex = app()->environment('production') && $indexable;
    @endphp
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="{{ $canIndex ? 'index, follow' : 'noindex, nofollow' }}">
    <link rel="canonical" href="{{ $canonical }}">
    @if($indexable)
        @foreach($languages as $language)
            @php($alternateSlug = trans('routes.'.$page, [], $language->locale))
            <link rel="alternate" hreflang="{{ $language->locale }}" href="{{ rtrim(config('app.url'), '/') }}/{{ $language->locale }}{{ $alternateSlug ? '/'.$alternateSlug : '' }}">
        @endforeach
        @php($defaultSlug = trans('routes.'.$page, [], $defaultLocale))
        <link rel="alternate" hreflang="x-default" href="{{ rtrim(config('app.url'), '/') }}/{{ $defaultLocale }}{{ $defaultSlug ? '/'.$defaultSlug : '' }}">
    @endif
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="{{ ['ro' => 'ro_RO', 'en' => 'en_GB', 'hu' => 'hu_HU', 'bg' => 'bg_BG'][app()->getLocale()] }}">
    <meta property="og:image" content="{{ rtrim(config('app.url'), '/') }}/images/social-card.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $brand }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ rtrim(config('app.url'), '/') }}/images/social-card.png">
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/favicon.svg') }}">
    <link rel="preload" href="{{ asset(app()->getLocale() === 'bg' ? 'fonts/noto-serif-bg.woff2' : 'fonts/dm-serif-display.ttf') }}" as="font" type="{{ app()->getLocale() === 'bg' ? 'font/woff2' : 'font/ttf' }}" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    @if($indexable)
    <script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $title, 'description' => $description, 'url' => $canonical, 'inLanguage' => app()->getLocale()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endif
</head>
<body id="top" class="{{ $page === 'demo' ? 'demo-body' : '' }}">
    <a href="#main" class="skip-link">{{ __('site.a11y.skip') }}</a>
    @if($page !== 'demo')
    <header class="site-header wrap">
        <x-brand/>
        <nav class="desktop-nav" aria-label="{{ __('site.nav.menu') }}">
            @foreach(['features', 'pricing', 'faq'] as $item)
                <a href="{{ route($item) }}" @if($page === $item) aria-current="page" @endif>{{ __('site.nav.'.$item) }}</a>
            @endforeach
        </nav>
        <div class="header-actions">
            <x-language-switcher :languages="$languages"/>
            @auth
                <a class="auth-link" href="{{ route('demo') }}">{{ __('site.nav.demo') }}</a>
            @else
                <a class="auth-link" href="{{ route('login') }}">{{ __('site.nav.login') }}</a>
            @endauth
            @auth
                <x-account-link :active="$page === 'dashboard'"/>
            @else
                <x-button :href="route('register')" icon="arrow" class="header-primary">{{ __('registration.create') }}</x-button>
            @endauth
        </div>
        <div class="mobile-header-actions">
            @auth<x-account-link :active="$page === 'dashboard'"/>@else<a class="auth-link" href="{{ route('login') }}">{{ __('site.nav.login') }}</a>@endauth
            <x-mobile-menu :languages="$languages" :page="$page"/>
        </div>
    </header>
    @endif
    <main id="main" tabindex="-1">@yield('content')</main>
    @if($page !== 'demo')
    <footer class="site-footer wrap">
        <div><x-brand/><p>{{ __('site.footer.line') }}</p></div>
        <div class="footer-links"><a href="{{ route('faq') }}">{{ __('site.nav.faq') }}</a><a href="#top">{{ __('site.footer.top') }} ↑</a><span class="status-note"><i></i>{{ __('site.footer.status') }}</span></div>
        <div class="footer-bottom"><span>© {{ date('Y') }} {{ $brand }}</span><span>{{ $languages->pluck('locale')->map(fn ($code) => strtoupper($code))->implode(' · ') }}</span></div>
    </footer>
    @endif
</body>
</html>
