@if($documents->isEmpty())
<div class="library-empty"><span class="feature-icon"><x-icon name="document" :size="32"/></span><h2>{{ __('documents.empty_title') }}</h2><p>{{ __('documents.empty_text') }}</p>@can('create', [App\Models\Document::class, $workspace])<x-button :href="route('documents.create')" icon="upload">{{ __('documents.upload') }}</x-button>@endcan</div>
@else
<div class="library-table-scroll document-desktop-list"><table class="library-table document-table"><colgroup><col class="document-col-title"><col class="document-col-category"><col class="document-col-status"><col class="document-col-amount"><col class="document-col-date"><col class="document-col-summary"></colgroup><thead><tr><th>{{ __('documents.title') }}</th><th>{{ __('documents.category_id') }}</th><th>{{ __('documents.stored') }}</th><th>{{ __('documents.amount') }}</th><th>{{ __('documents.added') }}</th><th>{{ __('documents.summary_column') }}</th></tr></thead><tbody>
@foreach($documents as $record)
<tr><td><a class="document-title-link" href="{{ route('documents.show', ['document' => $record->id]) }}"><span @class(['file-symbol', 'is-pdf' => $record->mime_type === 'application/pdf'])><x-icon name="document"/></span><span><strong>{{ $record->title }}</strong><small>{{ $record->supplier ?: strtoupper(str_replace(['application/', 'image/'], '', $record->mime_type)) }} · {{ $record->page_count }} {{ __('documents.pages') }}</small></span></a></td><td data-label="{{ __('documents.category_id') }}"><span class="document-chip">{{ $record->category?->label() ?? __('documents.no_category') }}</span></td><td data-label="{{ __('documents.stored') }}"><span class="stored-status"><i></i>{{ $record->trashed() ? __('documents.trash') : __('documents.stored') }}</span></td><td data-label="{{ __('documents.amount') }}">{{ $record->decimalAmount() !== null ? $record->decimalAmount().' '.$record->currency : '—' }}</td><td data-label="{{ __('documents.added') }}">{{ $record->created_at->timezone(config('vault.display_timezone'))->format('d.m.Y') }}</td><td class="document-summary" data-label="{{ __('documents.summary_column') }}">{{ $record->summary ?: '—' }}</td></tr>
@endforeach
</tbody></table></div>
<div class="document-mobile-list">
@foreach($documents as $record)
<article class="document-mobile-card">
    <a class="mobile-document-heading" href="{{ route('documents.show', ['document' => $record->id]) }}">
        <span @class(['file-symbol', 'is-pdf' => $record->mime_type === 'application/pdf'])><x-icon name="document"/></span>
        <span class="mobile-document-name"><strong>{{ $record->title }}</strong>@if($record->supplier)<span>{{ $record->supplier }}</span>@endif</span>
        <span class="mobile-document-chevron" aria-hidden="true">›</span>
    </a>
    @if($record->category || $record->decimalAmount() !== null)
    <div class="mobile-document-facts">
        @if($record->category)<span class="document-chip">{{ $record->category->label() }}</span>@endif
        @if($record->decimalAmount() !== null)<span class="mobile-document-amount" aria-label="{{ __('documents.amount') }}">{{ $record->decimalAmount() }} <span>{{ $record->currency }}</span></span>@endif
    </div>
    @endif
    @if($record->summary)<p class="mobile-document-summary">{{ $record->summary }}</p>@endif
    <div class="mobile-document-footer">
        <span>{{ strtoupper(str_replace(['application/', 'image/'], '', $record->mime_type)) }} · {{ $record->page_count }} {{ trans_choice('documents.page_count', $record->page_count) }}</span>
        <time datetime="{{ $record->created_at->toDateString() }}" aria-label="{{ __('documents.added') }}">{{ $record->created_at->timezone(config('vault.display_timezone'))->format('d.m.Y') }}</time>
        @can('delete', $record)<a class="mobile-document-delete" href="{{ route('documents.delete-confirmation', ['document' => $record->id]) }}" aria-label="{{ __('documents.delete') }}: {{ $record->title }}" title="{{ __('documents.delete') }}"><x-icon name="trash" :size="18"/></a>@endcan
    </div>
</article>
@endforeach
</div>
@endif
