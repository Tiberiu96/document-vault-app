<section class="features-section wrap" id="features">
    <div class="section-heading"><span class="eyebrow">{{ __('site.features.kicker') }}</span><h2>{{ __('site.features.title') }}</h2></div>
    <div class="features-grid">
        @foreach(['scan' => 'scan', 'organise' => 'folder', 'ocr' => 'sparkle', 'remind' => 'bell'] as $feature => $icon)
        <article class="feature"><div class="feature-icon"><x-icon :name="$icon" :size="25"/></div><h3>{{ __('site.features.'.$feature.'.title') }}</h3><p>{{ __('site.features.'.$feature.'.text') }}</p></article>
        @endforeach
    </div>
    <p class="section-note"><x-icon name="clock" :size="14"/>{{ __('site.features.status') }}</p>
</section>
