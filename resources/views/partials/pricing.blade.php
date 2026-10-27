<section class="pricing-section wrap" id="plans">
    <div class="section-heading"><span class="eyebrow">{{ __('site.pricing.kicker') }}</span><{{ $page === 'pricing' ? 'h1' : 'h2' }}>{{ __('site.pricing.title') }}</{{ $page === 'pricing' ? 'h1' : 'h2' }}><p>{{ __('site.pricing.text') }}</p></div>
    <div class="pricing-grid">
        @foreach(['free', 'personal', 'business'] as $plan)
        <article class="plan-card {{ $plan === 'personal' ? 'featured' : '' }}">
            <div class="plan-label">{{ __('site.pricing.'.$plan) }}<x-icon :name="$plan === 'business' ? 'folder' : 'document'"/></div>
            <h3>{{ __('site.pricing.'.($plan === 'free' ? 'proposed' : 'soon')) }}</h3>
            <p class="plan-price">{{ __('site.pricing.tba') }}</p>
            <ul>@foreach($plan === 'free' ? ['pages','size','quotas'] : ($plan === 'business' ? ['team','more'] : ['more','quotas']) as $benefit)<li><x-icon name="check" :size="16"/>{{ __('site.pricing.'.$benefit) }}</li>@endforeach</ul>
            <x-button :href="route('demo')" :variant="$plan === 'personal' ? 'primary' : 'outline'" icon="arrow">{{ __('site.nav.demo') }}</x-button>
        </article>
        @endforeach
    </div>
    <p class="section-note">{{ __('site.pricing.currency', ['currency' => $currency]) }}</p>
</section>
