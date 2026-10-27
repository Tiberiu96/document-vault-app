@props(['languages', 'page'])
<details class="mobile-menu">
    <summary aria-label="{{ __('site.nav.menu') }}"><x-icon name="menu" :size="23"/></summary>
    <div class="mobile-menu-panel">
        <nav class="mobile-menu-links" aria-label="{{ __('site.nav.menu') }}">
            @foreach(['features', 'pricing', 'faq', 'demo'] as $item)<a href="{{ route($item) }}" @if($page === $item) aria-current="page" @endif>{{ __('site.nav.'.$item) }}</a>@endforeach
        </nav>
        <div class="mobile-menu-languages"><x-language-switcher :languages="$languages"/></div>
        @auth
            <form method="POST" action="{{ route('logout') }}">@csrf<x-button type="submit" variant="outline">{{ __('site.nav.logout') }}</x-button></form>
        @else
            <x-button :href="route('register')" icon="arrow">{{ __('registration.create') }}</x-button>
        @endauth
    </div>
</details>
