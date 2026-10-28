@props(['action'])
@if(config('turnstile.enabled'))
    @if(app(\App\Services\Security\Turnstile::class)->configured())
        <div class="turnstile-field" data-turnstile-field data-site-key="{{ config('turnstile.site_key') }}" data-action="{{ $action }}" data-language="{{ app()->getLocale() }}" data-waiting="{{ __('turnstile.waiting') }}" data-failed="{{ __('turnstile.failed') }}">
            <div data-turnstile-container></div>
            <p class="field-help" data-turnstile-status role="status" aria-live="polite">{{ __('turnstile.waiting') }}</p>
            <noscript><p class="form-alert">{{ __('turnstile.javascript') }}</p></noscript>
        </div>
        @once
            @push('scripts')
                <script src="{{ asset('js/turnstile.js') }}?v={{ filemtime(public_path('js/turnstile.js')) }}" defer></script>
                <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=vaultyTurnstileReady&amp;render=explicit" defer></script>
            @endpush
        @endonce
    @else
        <p class="form-alert" role="alert">{{ __('turnstile.unavailable') }}</p>
    @endif
@endif
