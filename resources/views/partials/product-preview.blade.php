<div class="product-preview" role="img" aria-label="{{ __('site.common.preview') }} — {{ __('site.common.demo') }}">
    <div class="preview-chrome"><span></span><span></span><span></span><p>{{ config('app.name') }} / {{ __('site.common.personal') }}</p><x-icon name="shield" :size="12"/></div>
    <div class="preview-shell">
        <aside class="preview-sidebar"><strong>{{ config('app.name') }}<b>.</b></strong><div class="preview-workspace"><span>P</span>{{ __('site.common.personal') }}<small>⌄</small></div>
            @foreach(['home','documents','categories','reminders'] as $item)<div @class(['preview-nav', 'selected' => $loop->first])><x-icon :name="['home'=>'home','documents'=>'document','categories'=>'folder','reminders'=>'bell'][$item]" :size="13"/>{{ $item === 'home' ? __('site.nav.home') : __('site.common.'.$item) }}</div>@endforeach
            <div class="preview-leaf"><x-branch/><p>{{ __('site.footer.line') }}</p></div>
        </aside>
        <div class="preview-main">
            <div class="preview-search"><x-icon name="search" :size="12"/>{{ __('site.common.search') }}<span>⌘ K</span></div>
            <span class="eyebrow">{{ __('site.demo.greeting') }}</span>
            <h2>{{ __('site.demo.title') }}</h2><p class="preview-subtitle">{{ __('site.demo.subtitle') }}</p>
            <div class="preview-stats">
                @foreach([['document','4','total'],['database','2,4 MB','storage'],['bell','1','active']] as [$icon,$value,$label])
                <div><span class="mini-icon {{ $icon }}"><x-icon :name="$icon" :size="15"/></span><strong>{{ $value }}</strong><small>{{ __('site.common.'.$label) }}</small></div>
                @endforeach
            </div>
            <div class="preview-table"><h3>{{ __('site.common.recent') }}<span>↗</span></h3>
                @foreach([['energy','bills'],['rent','contracts'],['car','insurance'],['internet','bills']] as [$doc,$cat])
                <div class="preview-row"><span class="mini-file"><x-icon name="document" :size="14"/></span><div>{{ __('site.documents.'.$doc) }}<small>PDF · {{ __('site.common.personal') }}</small></div><span class="badge {{ $cat }}">{{ __('site.common.'.$cat) }}</span><i></i></div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="preview-caption"><span class="live-dot"></span>{{ __('site.common.preview') }}<span>{{ __('site.common.demo') }}</span></div>
</div>
