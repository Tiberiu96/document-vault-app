@props(['languages'])
<details class="member-language-menu" data-member-language-menu>
    <summary aria-label="{{ __('site.nav.language') }}" title="{{ __('site.nav.language') }}">
        <x-icon name="globe" :size="18"/>
        <span>{{ strtoupper(app()->getLocale()) }}</span>
        <x-icon name="chevron" :size="14"/>
    </summary>
    <div class="member-language-panel">
        <x-language-switcher :languages="$languages"/>
    </div>
</details>
