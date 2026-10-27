<nav class="language-switcher" aria-label="{{ __('site.nav.language') }}">
    <x-icon name="globe" :size="16"/>
    @foreach($languages as $language)
        <a href="{{ Route::localizedSwitcherUrl($language->locale) }}" lang="{{ $language->locale }}" hreflang="{{ $language->locale }}" aria-label="{{ $language->native_name }}" @if($language->locale === app()->getLocale()) aria-current="true" @endif>{{ strtoupper($language->locale) }}</a>
    @endforeach
</nav>
