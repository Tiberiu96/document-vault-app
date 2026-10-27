<section class="faq-section wrap" id="questions">
    <div class="section-heading"><span class="eyebrow">{{ __('site.nav.faq') }}</span><{{ $page === 'faq' ? 'h1' : 'h2' }}>{{ __('site.faq.title') }}</{{ $page === 'faq' ? 'h1' : 'h2' }}></div>
    <div class="faq-list">@foreach(range(1,4) as $number)<details><summary>{{ __('site.faq.q'.$number) }}<span>+</span></summary><p>{{ __('site.faq.a'.$number) }}</p></details>@endforeach</div>
</section>
